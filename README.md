# zip-stream

Write a ZIP straight to the client without building it first.

```php
use SugarCommerce\ZipStream\Archive;

header( 'Content-Type: application/zip' );
header( 'Content-Disposition: attachment; filename="order.zip"' );

$zip = new Archive( static fn( string $bytes ) => print $bytes );

foreach ( $files as $file ) {
    $handle = fopen( $file['url'], 'rb' );

    $zip->add( $file['name'], $handle, expect: $file['size'] );

    fclose( $handle );
}

$zip->add_string( 'licence.txt', $licence );
$zip->finish();
```

Nothing is held. A forty-gigabyte archive costs one 256 KiB buffer rather than
forty gigabytes of disk, and the first byte reaches the client before the last
member has been read — which is the difference between a download that starts
and a request that times out while a temporary file is assembled.

## What it does not do

**It does not compress.** Everything anybody streams this way — audio, video,
images, an already-zipped plugin — is compressed already, so deflating costs
CPU to make the result fractionally larger. Storing also means the bytes
written are the bytes read, which is what makes the streaming honest: there is
no point at which the library knows something the client does not.

**It cannot tell you the length in advance.** A stored archive's size is the
sum of its members plus a fixed overhead per member, so you *can* compute it —
but only if you trust every size you were given. Send it without a
`Content-Length` and the browser shows a progress bar without a total, which is
the honest trade. Announcing a length you then do not produce truncates the
download.

## Sizes and checksums

Neither is known until a member has been read, and the header goes out first.
That is what a data descriptor is for: the header says "the numbers follow",
the member streams, and the real numbers go afterwards. Bit 3 of the general
purpose flag announces it and every reader since 1993 understands it.

ZIP64 turns on by itself — per member when one passes 4 GiB, and for the
archive when the count or the totals do. Pass `expect:` when you know a size
and the member's header is reserved for the larger fields up front; be wrong
about it and nothing breaks, because the central directory at the end is what
readers actually trust.

## Names

Anything that could escape the archive is removed: `..` segments, leading
slashes, null bytes, and backslashes — a Windows reader treats those as
separators, so they are a second way to write the same escape. An entry called
`../../etc/cron.d/x` is a real attack on anything that extracts without
checking, and plenty does.

Names are written as UTF-8 with the flag that says so, so `Café — Master.wav`
survives the round trip.

## Timestamps

The format stores local wall-clock time with no zone, from MS-DOS, with
two-second resolution. That is not a choice — it is what the field is. Anything
before 1980 is clamped, because a negative year produces an archive that will
not open.
