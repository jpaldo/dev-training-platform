<?php

namespace App\Support;

/**
 * A Google Drive file, Google Doc, Slides deck or Sheet recognized from a link, with the address that embeds its
 * preview and the name of the Google app it opens in. Only `drive.google.com` and `docs.google.com` count, and only
 * links that carry a file id; folders, Forms and every other link are plain links (`fromUrl()` returns null).
 */
final class GoogleDriveLink
{
    /** The characters of a Drive file id. */
    private const FILE_ID = '[A-Za-z0-9_-]+';

    /** The Docs editors on `docs.google.com`, as path segment => label. */
    private const DOCS_EDITORS = [
        'document' => 'Google Docs',
        'presentation' => 'Google Slides',
        'spreadsheets' => 'Google Sheets',
    ];

    public function __construct(
        public readonly string $previewUrl,
        public readonly string $label,
    ) {}

    /**
     * The Drive file the link points at: `https://drive.google.com/file/d/{id}/…`, `https://drive.google.com/open?id={id}`
     * or `https://docs.google.com/{document|presentation|spreadsheets}/d/{id}/…` (each also with a `/u/{n}/` account
     * segment), over http or https. Anything else is null.
     */
    public static function fromUrl(string $url): ?self
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }

        $path = preg_replace('#^/u/\d+(?=/)#', '', $parts['path'] ?? '');

        return match (strtolower($parts['host'] ?? '')) {
            'drive.google.com' => self::driveFile($path, $parts['query'] ?? ''),
            'docs.google.com' => self::docsFile($path),
            default => null,
        };
    }

    /**
     * A file on `drive.google.com`, by its `/file/d/{id}` path or its `open?id={id}` query.
     */
    private static function driveFile(string $path, string $query): ?self
    {
        $fileId = null;

        if (preg_match('#^/file/d/('.self::FILE_ID.')(?:/|$)#', $path, $matches) === 1) {
            $fileId = $matches[1];
        } elseif ($path === '/open') {
            parse_str($query, $parameters);
            $id = $parameters['id'] ?? null;
            $fileId = is_string($id) && preg_match('#^'.self::FILE_ID.'$#', $id) === 1 ? $id : null;
        }

        return $fileId === null ? null : new self("https://drive.google.com/file/d/{$fileId}/preview", 'Google Drive');
    }

    /**
     * A Doc, Slides deck or Sheet on `docs.google.com`, by its `/{editor}/d/{id}` path.
     */
    private static function docsFile(string $path): ?self
    {
        $editors = implode('|', array_keys(self::DOCS_EDITORS));

        if (preg_match('#^/('.$editors.')/d/('.self::FILE_ID.')(?:/|$)#', $path, $matches) !== 1) {
            return null;
        }

        return new self("https://docs.google.com/{$matches[1]}/d/{$matches[2]}/preview", self::DOCS_EDITORS[$matches[1]]);
    }
}
