<?php
/**
 * A ZIP written as it is sent.
 *
 * @package   SugarCommerce\ZipStream
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\ZipStream;

/**
 * Class Archive
 *
 * Builds the archive in the order a reader expects it, and sends each part as
 * soon as it exists. Nothing is held: a forty-gigabyte download costs one
 * buffer rather than forty gigabytes of disk, and the first byte reaches the
 * client before the last member has been read.
 *
 * Members are stored, not deflated. Everything anybody sells this way — audio,
 * video, images, a zip of a plugin — is already compressed, so deflating costs
 * CPU to make the file fractionally larger. It also means the bytes written
 * are the bytes read, which is what makes streaming honest.
 *
 * Sizes and checksums are not known until a member has been read, so each one
 * is followed by a data descriptor — the part of the format that exists for
 * exactly this. ZIP64 turns on per member when one exceeds 4 GiB, and for the
 * archive when the totals do.
 *
 * ```php
 * $zip = new Archive( static fn( string $bytes ) => print $bytes );
 *
 * $zip->add( 'master.wav', $handle );
 * $zip->finish();
 * ```
 *
 * @since 1.0.0
 */
final class Archive {

	/**
	 * The point at which a 32-bit field can no longer hold the answer.
	 */
	private const LIMIT = 0xFFFFFFFF;

	/**
	 * How much to move at a time.
	 *
	 * Large enough that the syscall overhead disappears, small enough that a
	 * worker holding several of these at once is not the reason a box runs
	 * out of memory.
	 */
	private const CHUNK = 262144; // 256 KiB.

	/**
	 * Members written so far.
	 *
	 * @var Entry[]
	 */
	private array $entries = array();

	/**
	 * Names already written, lower-cased, as keys.
	 *
	 * @var array<string, true>
	 */
	private array $names = array();

	/**
	 * Bytes sent, which is also the offset of whatever comes next.
	 */
	private int $offset = 0;

	/**
	 * Whether finish() has run.
	 */
	private bool $finished = false;

	/**
	 * The member being written, between begin() and end().
	 *
	 * @var array{name: string, time: int, zip64: bool, start: int, crc: \HashContext, size: int}|null
	 */
	private ?array $open = null;

	/**
	 * @since 1.0.0
	 *
	 * @param \Closure $sink Receives each chunk of the archive. Printing it
	 *                       is the common case; a test collects it instead.
	 */
	public function __construct( private readonly \Closure $sink ) {}

	/**
	 * Add a member, reading it to the end.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $name   Path inside the archive. Directories are made
	 *                         by putting a slash in it. A name already used
	 *                         gets a number — see unused().
	 * @param resource $handle Anything fread() accepts. Closed by the caller.
	 * @param int|null $time   Modification time. Null uses the clock.
	 * @param int      $expect Size if it is known, which only decides whether
	 *                         the member is written with 64-bit fields. Being
	 *                         wrong costs nothing but a larger header.
	 *
	 * @return Entry What ended up in the archive.
	 *
	 * @throws \LogicException When the archive has already been finished.
	 */
	public function add( string $name, $handle, ?int $time = null, int $expect = 0 ): Entry {
		$this->begin( $name, $time, $expect );

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, self::CHUNK );

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$this->write( $chunk );
		}

		return $this->end();
	}

	/**
	 * Start a member whose bytes will be pushed in with write().
	 *
	 * For a member that arrives rather than being read: the body of an HTTP
	 * response handed over chunk by chunk, where there is no handle to give
	 * add(). Wiring such a body to a handle means a stream wrapper, and PHP's
	 * cannot be told which address to connect to — so the bytes are pushed
	 * here from whatever fetched them properly instead.
	 *
	 * The header goes out now. Nothing else may be added until end().
	 *
	 * @since 1.2.0
	 *
	 * @param string   $name   Path inside the archive. Cleaned and, if
	 *                         already used, numbered — as for add().
	 * @param int|null $time   Modification time. Null uses the clock.
	 * @param int      $expect Size if it is known; see add().
	 *
	 * @return string The name the member was written under.
	 *
	 * @throws \LogicException When the archive has been finished, or another
	 *                         member is still open.
	 */
	public function begin( string $name, ?int $time = null, int $expect = 0 ): string {
		if ( $this->finished ) {
			throw new \LogicException( 'Cannot add to an archive that has been finished.' );
		}

		if ( null !== $this->open ) {
			throw new \LogicException( 'Cannot begin "' . $name . '" while "' . $this->open['name'] . '" is still open: end() it first.' );
		}

		$name  = $this->unused( self::clean( $name ) );
		$time ??= time();

		// Decided before the header, because it changes the header. A member
		// declared 32-bit that turns out not to be cannot be rewritten: it
		// has already been sent.
		$zip64 = $expect >= self::LIMIT;

		$this->open = array(
			'name'  => $name,
			'time'  => $time,
			'zip64' => $zip64,
			'start' => $this->offset,
			'crc'   => hash_init( 'crc32b' ),
			'size'  => 0,
		);

		$this->emit( $this->local_header( $name, $time, $zip64 ) );

		return $name;
	}

	/**
	 * Push some of the open member's bytes.
	 *
	 * @since 1.2.0
	 *
	 * @param string $bytes The next bytes, in order. Any length; empty is
	 *                      allowed and changes nothing.
	 *
	 * @return void
	 *
	 * @throws \LogicException When no member is open.
	 */
	public function write( string $bytes ): void {
		if ( null === $this->open ) {
			throw new \LogicException( 'No member is open: begin() one first.' );
		}

		if ( '' === $bytes ) {
			return;
		}

		hash_update( $this->open['crc'], $bytes );

		$this->open['size'] += strlen( $bytes );

		$this->emit( $bytes );
	}

	/**
	 * Close the open member: its sizes and checksum go out after it.
	 *
	 * A member whose source failed partway is still closed properly — the
	 * archive stays readable, and the member is as long as what arrived.
	 * Whether that is acceptable is the caller's decision, made with the
	 * Entry's size in hand.
	 *
	 * @since 1.2.0
	 *
	 * @return Entry What ended up in the archive.
	 *
	 * @throws \LogicException When no member is open.
	 */
	public function end(): Entry {
		if ( null === $this->open ) {
			throw new \LogicException( 'No member is open to end.' );
		}

		[ 'name' => $name, 'time' => $time, 'zip64' => $zip64, 'start' => $start, 'crc' => $crc, 'size' => $size ] = $this->open;

		$this->open = null;
		$checksum   = (int) hexdec( hash_final( $crc ) );

		// A member that outgrew its header. The descriptor can carry the
		// larger numbers even when the header did not, and the central
		// directory is what readers actually trust.
		$zip64 = $zip64 || $size >= self::LIMIT;

		$this->emit( $this->descriptor( $checksum, $size, $zip64 ) );

		$entry           = new Entry( $name, $checksum, $size, $start, $time, $zip64 );
		$this->entries[] = $entry;

		return $entry;
	}

	/**
	 * Whether a member is open between begin() and end().
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_open(): bool {
		return null !== $this->open;
	}

	/**
	 * Add a member from a string.
	 *
	 * For the small things that belong in a download beside the files — a
	 * readme, a licence, a manifest — where opening a stream to hold four
	 * hundred bytes is ceremony.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $name     Path inside the archive.
	 * @param string   $contents The bytes.
	 * @param int|null $time     Modification time. Null uses the clock.
	 *
	 * @return Entry
	 */
	public function add_string( string $name, string $contents, ?int $time = null ): Entry {
		$handle = fopen( 'php://memory', 'r+' );

		fwrite( $handle, $contents );
		rewind( $handle );

		try {
			return $this->add( $name, $handle, $time, strlen( $contents ) );
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Write the central directory and close the archive.
	 *
	 * Nothing can be added afterwards, and nothing before this point is a
	 * readable ZIP — the directory at the end is how a reader finds anything.
	 *
	 * @since 1.0.0
	 *
	 * @return int Total bytes written.
	 *
	 * @throws \LogicException When a member begun with begin() was not ended:
	 *                         its descriptor would be missing, and a reader
	 *                         would find a directory pointing at half a file.
	 */
	public function finish(): int {
		if ( $this->finished ) {
			return $this->offset;
		}

		if ( null !== $this->open ) {
			throw new \LogicException( 'Cannot finish while "' . $this->open['name'] . '" is still open: end() it first.' );
		}

		$this->finished = true;
		$start          = $this->offset;

		foreach ( $this->entries as $entry ) {
			$this->emit( $this->central( $entry ) );
		}

		$this->emit( $this->directory_end( $start, $this->offset - $start ) );

		return $this->offset;
	}

	/**
	 * Bytes written so far.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function written(): int {
		return $this->offset;
	}

	/**
	 * Send a chunk, and remember how far along we are.
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return void
	 */
	private function emit( string $bytes ): void {
		$this->offset += strlen( $bytes );

		( $this->sink )( $bytes );
	}

	/**
	 * The header that precedes a member's bytes.
	 *
	 * Sizes and CRC are zero here and given afterwards in the descriptor,
	 * which is what bit 3 of the flags announces. It is the only way to
	 * write a member you have not read yet.
	 *
	 * @param string $name  Path inside the archive.
	 * @param int    $time  Modification time.
	 * @param bool   $zip64 Whether to reserve 64-bit fields.
	 *
	 * @return string
	 */
	private function local_header( string $name, int $time, bool $zip64 ): string {
		$extra = $zip64
			// Placeholders. The real numbers go in the descriptor and the
			// central directory; this only reserves the shape.
			? pack( 'vvPP', 0x0001, 16, 0, 0 )
			: '';

		return pack( 'V', 0x04034b50 )
			. pack( 'v', $zip64 ? 45 : 20 )     // Version needed.
			. pack( 'v', 0x0008 | 0x0800 )      // Data descriptor follows; name is UTF-8.
			. pack( 'v', 0 )                    // Stored.
			. self::dos_time( $time )
			. pack( 'VVV', 0, 0, 0 )            // CRC and both sizes: not yet known.
			. pack( 'v', strlen( $name ) )
			. pack( 'v', strlen( $extra ) )
			. $name
			. $extra;
	}

	/**
	 * The sizes and checksum, written after the member.
	 *
	 * @param int  $crc   CRC-32.
	 * @param int  $size  Bytes written.
	 * @param bool $zip64 Whether the fields are 64-bit.
	 *
	 * @return string
	 */
	private function descriptor( int $crc, int $size, bool $zip64 ): string {
		return pack( 'V', 0x08074b50 )
			. pack( 'V', $crc )
			. ( $zip64 ? pack( 'PP', $size, $size ) : pack( 'VV', $size, $size ) );
	}

	/**
	 * A member's entry in the central directory.
	 *
	 * @param Entry $entry The written member.
	 *
	 * @return string
	 */
	private function central( Entry $entry ): string {
		$big   = $entry->size >= self::LIMIT || $entry->offset >= self::LIMIT;
		$extra = '';

		if ( $big ) {
			// Order matters and is fixed by the spec: only the fields that
			// overflowed appear, in the order they appear in the record.
			$fields = '';

			if ( $entry->size >= self::LIMIT ) {
				$fields .= pack( 'PP', $entry->size, $entry->size );
			}

			if ( $entry->offset >= self::LIMIT ) {
				$fields .= pack( 'P', $entry->offset );
			}

			$extra = pack( 'vv', 0x0001, strlen( $fields ) ) . $fields;
		}

		return pack( 'V', 0x02014b50 )
			. pack( 'v', $big ? 45 : 20 )       // Version made by.
			. pack( 'v', $big ? 45 : 20 )       // Version needed.
			. pack( 'v', 0x0008 | 0x0800 )
			. pack( 'v', 0 )                    // Stored.
			. self::dos_time( $entry->time )
			. pack( 'V', $entry->crc )
			. pack( 'V', min( $entry->size, self::LIMIT ) )
			. pack( 'V', min( $entry->size, self::LIMIT ) )
			. pack( 'v', strlen( $entry->name ) )
			. pack( 'v', strlen( $extra ) )
			. pack( 'v', 0 )                    // Comment length.
			. pack( 'v', 0 )                    // Disk number.
			. pack( 'v', 0 )                    // Internal attributes.
			. pack( 'V', 0x81a40000 )           // External attributes: 0644.
			. pack( 'V', min( $entry->offset, self::LIMIT ) )
			. $entry->name
			. $extra;
	}

	/**
	 * The record a reader looks for first.
	 *
	 * It is at the end, which is why a ZIP cannot be read until it has been
	 * written — and why a download that dies halfway leaves nothing openable
	 * rather than a truncated archive that looks fine.
	 *
	 * @param int $start Where the central directory began.
	 * @param int $size  How long it is.
	 *
	 * @return string
	 */
	private function directory_end( int $start, int $size ): string {
		$count = count( $this->entries );
		$big   = $count >= 0xFFFF || $start >= self::LIMIT || $size >= self::LIMIT;
		$out   = '';

		if ( $big ) {
			// The 64-bit record, then a locator pointing at it. A reader that
			// understands neither still finds the 32-bit record after them
			// and sees the 0xFFFF markers telling it to look harder.
			$out .= pack( 'V', 0x06064b50 )
				. pack( 'P', 44 )               // Size of the rest of this record.
				. pack( 'vv', 45, 45 )
				. pack( 'VV', 0, 0 )            // This disk, and the one with the directory.
				. pack( 'PP', $count, $count )
				. pack( 'P', $size )
				. pack( 'P', $start );

			$out .= pack( 'V', 0x07064b50 )
				. pack( 'V', 0 )
				. pack( 'P', $start + $size )
				. pack( 'V', 1 );
		}

		return $out
			. pack( 'V', 0x06054b50 )
			. pack( 'vv', 0, 0 )
			. pack( 'v', min( $count, 0xFFFF ) )
			. pack( 'v', min( $count, 0xFFFF ) )
			. pack( 'V', min( $size, self::LIMIT ) )
			. pack( 'V', min( $start, self::LIMIT ) )
			. pack( 'v', 0 );                   // Comment length.
	}

	/**
	 * A timestamp in the format the archive uses.
	 *
	 * MS-DOS, from 1980, in local time with two-second resolution. Not a
	 * choice — it is what the field is.
	 *
	 * @param int $time Unix timestamp.
	 *
	 * @return string Four bytes: time then date.
	 */
	private static function dos_time( int $time ): string {
		$parts = getdate( max( $time, 315532800 ) ); // 1980-01-01, the epoch of the format.

		return pack(
			'vv',
			( $parts['hours'] << 11 ) | ( $parts['minutes'] << 5 ) | ( $parts['seconds'] >> 1 ),
			( ( $parts['year'] - 1980 ) << 9 ) | ( $parts['mon'] << 5 ) | $parts['mday']
		);
	}

	/**
	 * The name, or the first numbered variant of it not yet written.
	 *
	 * Two members with one name make an archive that loses a file on
	 * extraction — the second overwrites the first, or the extractor stops
	 * to ask. Compared without case, because macOS and Windows extract to
	 * file systems where `Readme.txt` and `readme.txt` are the same file.
	 *
	 * The number goes before the extension, the way a desktop names a
	 * second copy: `readme (2).txt`, `stems/kick (3).wav`.
	 *
	 * @param string $name Cleaned name.
	 *
	 * @return string
	 */
	private function unused( string $name ): string {
		$slash     = strrpos( $name, '/' );
		$directory = false === $slash ? '' : substr( $name, 0, $slash + 1 );
		$file      = false === $slash ? $name : substr( $name, $slash + 1 );

		// After the first character, so a dotfile is all name and no
		// extension: `.env (2)`, not ` (2).env`.
		$dot       = strrpos( $file, '.', 1 );
		$base      = false === $dot || 0 === $dot ? $file : substr( $file, 0, $dot );
		$extension = false === $dot || 0 === $dot ? '' : substr( $file, $dot );

		$candidate = $name;

		for ( $copy = 2; isset( $this->names[ mb_strtolower( $candidate ) ] ); $copy++ ) {
			$candidate = $directory . $base . ' (' . $copy . ')' . $extension;
		}

		$this->names[ mb_strtolower( $candidate ) ] = true;

		return $candidate;
	}

	/**
	 * A name that cannot escape the archive.
	 *
	 * An entry called `../../etc/cron.d/x` is a real attack on anything that
	 * extracts without checking, and plenty does. Backslashes go too: a
	 * Windows reader treats them as separators, so they are a second way to
	 * write the same escape.
	 *
	 * @param string $name Submitted name.
	 *
	 * @return string
	 */
	private static function clean( string $name ): string {
		$name = str_replace( array( '\\', "\0" ), array( '/', '' ), $name );
		$name = ltrim( $name, '/' );

		$parts = array_filter(
			explode( '/', $name ),
			static fn( string $part ): bool => '' !== $part && '.' !== $part && '..' !== $part
		);

		return implode( '/', $parts );
	}
}
