<?php
/**
 * One member of an archive, after it has been written.
 *
 * @package   SugarCommerce\ZipStream
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\ZipStream;

/**
 * Class Entry
 *
 * What the central directory needs to say about a member: where it started,
 * how big it turned out, and its checksum. All three are only known after the
 * member has been streamed, which is why they are recorded rather than
 * declared.
 *
 * @since 1.0.0
 */
final readonly class Entry {

	/**
	 * @since 1.0.0
	 *
	 * @param string $name   Path inside the archive.
	 * @param int    $crc    CRC-32 of the contents.
	 * @param int    $size   Bytes written.
	 * @param int    $offset Where the local header began.
	 * @param int    $time   Modification time, as a Unix timestamp.
	 * @param bool   $zip64  Whether this member needs 64-bit fields.
	 */
	public function __construct(
		public string $name,
		public int $crc,
		public int $size,
		public int $offset,
		public int $time,
		public bool $zip64,
	) {}
}
