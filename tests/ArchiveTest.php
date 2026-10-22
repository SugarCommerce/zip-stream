<?php
/**
 * Archive tests.
 *
 * Everything here that matters is verified by opening the result with PHP's
 * own ZipArchive: a format this old is only correct if the readers agree.
 *
 * @package SugarCommerce\ZipStream\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\ZipStream\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\ZipStream\Archive;

/**
 * Class ArchiveTest
 */
final class ArchiveTest extends TestCase {

	private string $bytes = '';

	private function archive(): Archive {
		$this->bytes = '';

		return new Archive( function ( string $chunk ): void {
			$this->bytes .= $chunk;
		} );
	}

	/**
	 * Open what was written, the way anything downloading it would.
	 */
	private function open(): \ZipArchive {
		$path = tempnam( sys_get_temp_dir(), 'zs' );

		file_put_contents( $path, $this->bytes );

		$zip = new \ZipArchive();

		$this->assertTrue( $zip->open( $path ) === true, 'The archive did not open.' );

		return $zip;
	}

	/**
	 * @param resource|null $ignored
	 */
	private function stream( string $contents ) {
		$handle = fopen( 'php://memory', 'r+' );

		fwrite( $handle, $contents );
		rewind( $handle );

		return $handle;
	}

	public function test_an_empty_archive_still_opens(): void {
		$this->archive()->finish();

		$this->assertSame( 0, $this->open()->numFiles );
	}

	public function test_one_member_reads_back(): void {
		$zip = $this->archive();

		$zip->add( 'hello.txt', $this->stream( 'hello world' ) );
		$zip->finish();

		$opened = $this->open();

		$this->assertSame( 1, $opened->numFiles );
		$this->assertSame( 'hello.txt', $opened->getNameIndex( 0 ) );
		$this->assertSame( 'hello world', $opened->getFromName( 'hello.txt' ) );
	}

	public function test_several_members_keep_their_order_and_contents(): void {
		$zip = $this->archive();

		foreach ( array( 'a.txt' => 'first', 'b.txt' => 'second', 'c.txt' => 'third' ) as $name => $body ) {
			$zip->add( $name, $this->stream( $body ) );
		}

		$zip->finish();

		$opened = $this->open();

		$this->assertSame( 3, $opened->numFiles );
		$this->assertSame( 'b.txt', $opened->getNameIndex( 1 ) );
		$this->assertSame( 'third', $opened->getFromName( 'c.txt' ) );
	}

	public function test_a_member_can_be_added_from_a_string(): void {
		$zip = $this->archive();

		$zip->add_string( 'readme.txt', 'Thanks for buying.' );
		$zip->finish();

		$this->assertSame( 'Thanks for buying.', $this->open()->getFromName( 'readme.txt' ) );
	}

	/**
	 * A second readme would overwrite the first on extraction.
	 */
	public function test_a_repeated_name_gets_a_number_before_its_extension(): void {
		$zip = $this->archive();

		$zip->add_string( 'readme.txt', 'one' );
		$second = $zip->add_string( 'readme.txt', 'two' );
		$zip->add_string( 'readme.txt', 'three' );
		$zip->finish();

		$opened = $this->open();

		$this->assertSame( 'readme (2).txt', $second->name );
		$this->assertSame( 3, $opened->numFiles );
		$this->assertSame( 'one', $opened->getFromName( 'readme.txt' ) );
		$this->assertSame( 'three', $opened->getFromName( 'readme (3).txt' ) );
	}

	/**
	 * macOS and Windows extract onto file systems that ignore case.
	 */
	public function test_names_differing_only_in_case_are_the_same_name(): void {
		$zip = $this->archive();

		$zip->add_string( 'Master.wav', 'a' );

		$this->assertSame( 'master (2).wav', $zip->add_string( 'master.wav', 'b' )->name );
	}

	public function test_numbering_keeps_the_directory_and_leaves_dotfiles_whole(): void {
		$zip = $this->archive();

		$zip->add_string( 'stems/kick.wav', 'a' );
		$zip->add_string( '.env', 'a' );
		$zip->add_string( 'LICENSE', 'a' );

		$this->assertSame( 'stems/kick (2).wav', $zip->add_string( 'stems/kick.wav', 'b' )->name );
		$this->assertSame( '.env (2)', $zip->add_string( '.env', 'b' )->name );
		$this->assertSame( 'LICENSE (2)', $zip->add_string( 'LICENSE', 'b' )->name );
		$this->assertSame( 'kick.wav', $zip->add_string( 'kick.wav', 'c' )->name, 'Another directory is another name.' );
	}

	/**
	 * A seller who uploaded "song (2).mp3" next to "song.mp3" twice.
	 */
	public function test_a_number_already_taken_is_skipped(): void {
		$zip = $this->archive();

		$zip->add_string( 'song.mp3', 'a' );
		$zip->add_string( 'song (2).mp3', 'b' );

		$this->assertSame( 'song (3).mp3', $zip->add_string( 'song.mp3', 'c' )->name );
	}

	public function test_directories_come_from_slashes_in_the_name(): void {
		$zip = $this->archive();

		$zip->add_string( 'stems/kick.wav', 'x' );
		$zip->finish();

		$this->assertSame( 'stems/kick.wav', $this->open()->getNameIndex( 0 ) );
	}

	public function test_a_larger_member_survives_chunking(): void {
		// Comfortably past the read buffer, so the loop runs several times
		// and the CRC is accumulated rather than taken in one go.
		$body = str_repeat( 'abcdefgh', 200_000 ); // 1.6 MB.
		$zip  = $this->archive();

		$zip->add( 'big.bin', $this->stream( $body ) );
		$zip->finish();

		$this->assertSame( $body, $this->open()->getFromName( 'big.bin' ) );
	}

	public function test_an_empty_member_is_written_rather_than_skipped(): void {
		$zip = $this->archive();

		$zip->add_string( 'empty.txt', '' );
		$zip->finish();

		$opened = $this->open();

		$this->assertSame( 1, $opened->numFiles );
		$this->assertSame( '', $opened->getFromName( 'empty.txt' ) );
	}

	/**
	 * The checksum is what a reader uses to decide the file arrived intact.
	 */
	public function test_the_checksum_matches_the_contents(): void {
		$zip   = $this->archive();
		$entry = $zip->add_string( 'hello.txt', 'hello world' );

		$zip->finish();

		$this->assertSame( crc32( 'hello world' ), $entry->crc );
		$this->assertSame( 11, $entry->size );
	}

	/**
	 * An entry called ../../etc/cron.d/x is a real attack on anything that
	 * extracts without checking, and plenty does.
	 */
	public function test_a_name_cannot_escape_the_archive(): void {
		$zip = $this->archive();

		$zip->add_string( '../../etc/passwd', 'x' );
		$zip->add_string( '/absolute.txt', 'y' );
		$zip->add_string( '..\\..\\windows\\system32\\z.txt', 'z' );
		$zip->finish();

		$opened = $this->open();

		$this->assertSame( 'etc/passwd', $opened->getNameIndex( 0 ) );
		$this->assertSame( 'absolute.txt', $opened->getNameIndex( 1 ) );
		$this->assertSame( 'windows/system32/z.txt', $opened->getNameIndex( 2 ) );
	}

	/**
	 * Windows drops trailing dots and spaces from each part of a path, so
	 * `.. ` and `...` are `..` once extracted there; a drive makes a name
	 * absolute.
	 */
	public function test_a_name_cannot_escape_the_archive_on_windows(): void {
		$zip = $this->archive();

		$zip->add_string( '.. /.. /a.txt', '1' );
		$zip->add_string( '...\\....\\b.txt', '2' );
		$zip->add_string( 'C:\\Windows\\c.dll', '3' );
		$zip->add_string( 'D:d.txt', '4' );
		$zip->add_string( '//server/share/e.txt', '5' );
		$zip->finish();

		$opened = $this->open();

		$this->assertSame( 'a.txt', $opened->getNameIndex( 0 ) );
		$this->assertSame( 'b.txt', $opened->getNameIndex( 1 ) );
		$this->assertSame( 'Windows/c.dll', $opened->getNameIndex( 2 ) );
		$this->assertSame( 'd.txt', $opened->getNameIndex( 3 ) );
		$this->assertSame( 'server/share/e.txt', $opened->getNameIndex( 4 ) );
	}

	/**
	 * A name is shown to whoever opens the archive: an override makes
	 * `txt.exe` display as `exe.txt`, and the archive declares UTF-8.
	 */
	public function test_a_name_is_safe_to_show(): void {
		$zip = $this->archive();

		$zip->add_string( "notes\n\u{202E}txt.exe", 'x' );
		$zip->add_string( "caf\xE9.txt", 'y' );
		$zip->finish();

		$opened = $this->open();

		$this->assertSame( 'notestxt.exe', $opened->getNameIndex( 0 ) );
		$this->assertTrue( mb_check_encoding( (string) $opened->getNameIndex( 1 ), 'UTF-8' ) );
		$this->assertSame( 'y', $opened->getFromIndex( 1 ) );
	}

	public function test_a_name_with_nothing_left_is_still_a_name(): void {
		$zip = $this->archive();

		$this->assertSame( 'unnamed', $zip->add_string( '../..', 'x' )->name );
		$this->assertSame( 'unnamed (2)', $zip->add_string( '', 'y' )->name );
		$zip->finish();

		$this->assertSame( 'y', $this->open()->getFromName( 'unnamed (2)' ) );
	}

	/**
	 * The length of a name is a sixteen-bit field. A longer one used to
	 * wrap, and the rest of the name was read as what followed it.
	 */
	public function test_a_name_too_long_for_the_format_is_cut(): void {
		$zip = $this->archive();

		$zip->add_string( str_repeat( 'é', 40_000 ) . '.txt', 'long' );
		$zip->add_string( 'after.txt', 'after' );
		$zip->finish();

		$opened = $this->open();

		$this->assertLessThanOrEqual( 0xFFFF, strlen( (string) $opened->getNameIndex( 0 ) ) );
		$this->assertTrue( mb_check_encoding( (string) $opened->getNameIndex( 0 ), 'UTF-8' ) );
		$this->assertSame( 'long', $opened->getFromIndex( 0 ) );
		$this->assertSame( 'after', $opened->getFromName( 'after.txt' ) );
	}

	/**
	 * Nothing has been written when the handle is refused, so the archive is
	 * not left with a member begun that nothing will end.
	 */
	public function test_a_handle_that_is_not_a_stream_is_refused_before_writing(): void {
		$zip = $this->archive();

		try {
			$zip->add( 'a.txt', 'not a handle' );
			$this->fail( 'A string is not a stream.' );
		} catch ( \InvalidArgumentException ) {
			$this->assertSame( 0, $zip->written() );
			$this->assertFalse( $zip->is_open() );
		}
	}

	public function test_a_null_byte_is_stripped_from_a_name(): void {
		$zip = $this->archive();

		$zip->add_string( "safe.txt\0.php", 'x' );
		$zip->finish();

		$this->assertSame( 'safe.txt.php', $this->open()->getNameIndex( 0 ) );
	}

	public function test_a_unicode_name_reads_back(): void {
		$zip = $this->archive();

		$zip->add_string( 'Café — Master.wav', 'x' );
		$zip->finish();

		$this->assertSame( 'Café — Master.wav', $this->open()->getNameIndex( 0 ) );
	}

	/**
	 * Nothing before the central directory is a readable ZIP, so adding
	 * after it would produce a member no reader ever finds.
	 */
	public function test_adding_after_finishing_is_a_programming_error(): void {
		$zip = $this->archive();

		$zip->finish();

		$this->expectException( \LogicException::class );

		$zip->add_string( 'late.txt', 'x' );
	}

	public function test_finishing_twice_is_harmless(): void {
		$zip = $this->archive();

		$zip->add_string( 'a.txt', 'x' );

		$this->assertSame( $zip->finish(), $zip->finish() );
	}

	public function test_written_reports_the_total(): void {
		$zip = $this->archive();

		$zip->add_string( 'a.txt', 'hello' );
		$total = $zip->finish();

		$this->assertSame( strlen( $this->bytes ), $total );
		$this->assertSame( $total, $zip->written() );
	}

	/**
	 * A member declared larger than a 32-bit field can hold is written with
	 * 64-bit ones. The declared size is a hint, so this checks the shape
	 * without moving four gigabytes.
	 */
	public function test_a_member_declared_huge_is_written_as_zip64(): void {
		$zip   = $this->archive();
		$entry = $zip->add( 'claims-to-be-big.bin', $this->stream( 'small' ), expect: 0xFFFFFFFF );

		$zip->finish();

		$this->assertTrue( $entry->zip64 );
		$this->assertSame( 'small', $this->open()->getFromName( 'claims-to-be-big.bin' ) );
	}

	public function test_a_wrong_size_hint_still_produces_a_readable_archive(): void {
		$zip = $this->archive();

		$zip->add( 'a.txt', $this->stream( 'actually tiny' ), expect: 999_999_999 );
		$zip->finish();

		$this->assertSame( 'actually tiny', $this->open()->getFromName( 'a.txt' ) );
	}

	/**
	 * The modification time is carried through, which is what stops every
	 * extracted file claiming to have been made the moment it was unzipped.
	 *
	 * Read out of the bytes rather than through ZipArchive, because the
	 * field is local wall-clock time with no zone attached: PHP converts it
	 * back using its own timezone and libzip using the operating system's,
	 * and on a machine where those differ the two answers are hours apart
	 * through no fault of the archive.
	 */
	public function test_the_modification_time_survives(): void {
		$when = mktime( 12, 30, 0, 6, 15, 2024 );
		$zip  = $this->archive();

		$zip->add_string( 'a.txt', 'x', $when );
		$zip->finish();

		// Local header: signature (4), version (2), flags (2), method (2),
		// then the time and date this is about.
		$fields = unpack( 'vtime/vdate', substr( $this->bytes, 10, 4 ) );
		$time   = $fields['time'];
		$date   = $fields['date'];

		$this->assertSame( 12, $time >> 11 );
		$this->assertSame( 30, ( $time >> 5 ) & 0x3F );
		$this->assertSame( 2024, ( ( $date >> 9 ) & 0x7F ) + 1980 );
		$this->assertSame( 6, ( $date >> 5 ) & 0x0F );
		$this->assertSame( 15, $date & 0x1F );
	}

	/**
	 * A date before the format's own epoch cannot be represented, and
	 * writing a negative year produces an archive that will not open.
	 */
	public function test_a_prehistoric_date_is_clamped_rather_than_corrupting(): void {
		$zip = $this->archive();

		$zip->add_string( 'a.txt', 'x', 0 );
		$zip->finish();

		$this->assertSame( 'x', $this->open()->getFromName( 'a.txt' ) );
	}

	/**
	 * The year field is seven bits from 1980; 2108 and later overflowed into
	 * the month.
	 */
	public function test_a_far_future_date_is_clamped_rather_than_corrupting(): void {
		$zip = $this->archive();

		$zip->add_string( 'a.txt', 'x', mktime( 12, 0, 0, 6, 15, 2200 ) );
		$zip->finish();

		$date = unpack( 'vdate', substr( $this->bytes, 12, 2 ) )['date'];

		$this->assertSame( 2107, ( ( $date >> 9 ) & 0x7F ) + 1980 );
		$this->assertSame( 12, ( $date >> 5 ) & 0x0F );
	}

	/* ─── Pushed members (1.2.0) ────────────────────────────────────── */

	/**
	 * A body that arrives in pieces — an HTTP response handed over chunk by
	 * chunk — goes in without a handle.
	 */
	public function test_a_pushed_member_reads_back(): void {
		$zip  = $this->archive();
		$name = $zip->begin( 'take.wav' );

		foreach ( array( 'one ', '', 'two ', 'three' ) as $chunk ) {
			$zip->write( $chunk );
		}

		$entry = $zip->end();

		$zip->add_string( 'readme.txt', 'after' );
		$zip->finish();

		$this->assertSame( 'take.wav', $name );
		$this->assertSame( 13, $entry->size );
		$this->assertSame( (int) hexdec( hash( 'crc32b', 'one two three' ) ), $entry->crc );
		$this->assertSame( 'one two three', $this->open()->getFromName( 'take.wav' ) );
		$this->assertSame( 'after', $this->open()->getFromName( 'readme.txt' ) );
	}

	/**
	 * Pushing and reading from a handle write the same bytes, so the two
	 * cannot drift apart.
	 */
	public function test_pushing_writes_what_adding_writes(): void {
		$zip = $this->archive();

		$zip->add( 'a.bin', $this->stream( 'same bytes' ), 1_700_000_000 );
		$zip->finish();

		$added = $this->bytes;
		$zip   = $this->archive();

		$zip->begin( 'a.bin', 1_700_000_000 );
		$zip->write( 'same ' );
		$zip->write( 'bytes' );
		$zip->end();
		$zip->finish();

		$this->assertSame( $added, $this->bytes );
	}

	public function test_a_pushed_name_is_cleaned_and_numbered_like_any_other(): void {
		$zip = $this->archive();

		$zip->add_string( 'a.txt', 'first' );

		$this->assertSame( 'a (2).txt', $zip->begin( '../a.txt' ) );
		$this->assertTrue( $zip->is_open() );

		$zip->end();

		$this->assertFalse( $zip->is_open() );
	}

	public function test_an_empty_pushed_member_is_still_a_member(): void {
		$zip = $this->archive();

		$zip->begin( 'empty.txt' );
		$zip->end();
		$zip->finish();

		$this->assertSame( '', $this->open()->getFromName( 'empty.txt' ) );
	}

	public function test_two_members_cannot_be_open_at_once(): void {
		$zip = $this->archive();

		$zip->begin( 'a.txt' );

		$this->expectException( \LogicException::class );

		$zip->add_string( 'b.txt', 'x' );
	}

	public function test_writing_with_nothing_open_is_a_programming_error(): void {
		$this->expectException( \LogicException::class );

		$this->archive()->write( 'x' );
	}

	public function test_ending_with_nothing_open_is_a_programming_error(): void {
		$this->expectException( \LogicException::class );

		$this->archive()->end();
	}

	/**
	 * A member left open has no descriptor, and a directory written after
	 * it would point at half a file.
	 */
	public function test_finishing_with_a_member_open_is_a_programming_error(): void {
		$zip = $this->archive();

		$zip->begin( 'a.txt' );
		$zip->write( 'x' );

		$this->expectException( \LogicException::class );

		$zip->finish();
	}
}
