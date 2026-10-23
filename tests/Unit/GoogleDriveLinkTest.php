<?php

namespace Tests\Unit;

use App\Support\GoogleDriveLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GoogleDriveLinkTest extends TestCase
{
    #[DataProvider('driveLinks')]
    public function test_a_drive_file_link_gives_its_embed_url_and_label(string $url, string $previewUrl, string $label): void
    {
        $link = GoogleDriveLink::fromUrl($url);

        $this->assertNotNull($link);
        $this->assertSame($previewUrl, $link->previewUrl);
        $this->assertSame($label, $link->label);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function driveLinks(): array
    {
        return [
            'drive file view' => ['https://drive.google.com/file/d/1aB_c-D2/view?usp=sharing', 'https://drive.google.com/file/d/1aB_c-D2/preview', 'Google Drive'],
            'drive file without a trailing segment' => ['https://drive.google.com/file/d/1aB_c-D2', 'https://drive.google.com/file/d/1aB_c-D2/preview', 'Google Drive'],
            'drive file for a signed-in account' => ['https://drive.google.com/u/1/file/d/1aB_c-D2/view', 'https://drive.google.com/file/d/1aB_c-D2/preview', 'Google Drive'],
            'drive open link' => ['https://drive.google.com/open?id=1aB_c-D2', 'https://drive.google.com/file/d/1aB_c-D2/preview', 'Google Drive'],
            'drive open link with more parameters' => ['https://drive.google.com/open?usp=drive_link&id=1aB_c-D2', 'https://drive.google.com/file/d/1aB_c-D2/preview', 'Google Drive'],
            'google doc' => ['https://docs.google.com/document/d/abc123/edit?usp=sharing', 'https://docs.google.com/document/d/abc123/preview', 'Google Docs'],
            'google slides' => ['https://docs.google.com/presentation/d/abc123/edit#slide=id.p', 'https://docs.google.com/presentation/d/abc123/preview', 'Google Slides'],
            'google sheet for a signed-in account' => ['https://docs.google.com/u/0/spreadsheets/d/abc123/edit#gid=0', 'https://docs.google.com/spreadsheets/d/abc123/preview', 'Google Sheets'],
            'plain http and upper-case host' => ['http://Docs.Google.com/document/d/abc123/view', 'https://docs.google.com/document/d/abc123/preview', 'Google Docs'],
        ];
    }

    #[DataProvider('otherLinks')]
    public function test_any_other_link_is_not_a_drive_file(string $url): void
    {
        $this->assertNull(GoogleDriveLink::fromUrl($url));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherLinks(): array
    {
        return [
            'drive folder' => ['https://drive.google.com/drive/folders/1aB_c-D2'],
            'drive home' => ['https://drive.google.com/drive/my-drive'],
            'google form' => ['https://docs.google.com/forms/d/abc123/viewform'],
            'youtube' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'other host with a drive path' => ['https://example.com/file/d/abc123/view'],
            'look-alike drive host' => ['https://drive.google.com.example.com/file/d/abc123/view'],
            'look-alike docs host' => ['https://docs.google.com.example.com/document/d/abc123/edit'],
            'subdomain of the docs host' => ['https://evil.docs.google.com/document/d/abc123/edit'],
            'drive host behind credentials' => ['https://drive.google.com@example.com/file/d/abc123/view'],
            'drive file without an id' => ['https://drive.google.com/file/d//view'],
            'drive open link without an id' => ['https://drive.google.com/open'],
            'drive open link with an empty id' => ['https://drive.google.com/open?id='],
            'drive open link with markup in the id' => ['https://drive.google.com/open?id=abc"><script>'],
            'docs link without an id' => ['https://docs.google.com/document/d/'],
            'another scheme' => ['ftp://drive.google.com/file/d/abc123/view'],
            'not a url' => ['drive.google.com/file/d/abc123/view'],
        ];
    }
}
