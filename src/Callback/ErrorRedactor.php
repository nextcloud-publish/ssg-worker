<?php

declare(strict_types=1);

namespace App\Callback;

/**
 * Strips the deployment's filesystem layout out of a failure message before it
 * is POSTed to a client-supplied URL.
 *
 * The pipeline's exceptions embed absolute paths on purpose, because that is
 * what an operator needs from a log. callback_status_url is chosen by whoever
 * called the API, so sending the raw string there hands them a map of the
 * volume layout, the mount names and how far this uid reaches.
 *
 * Two audiences, two strings: BuildJobHandler logs the raw message and redacts
 * only on the way to the callback. The result should stay DIAGNOSTIC without
 * being a disclosure -- "<build>/.../content.tar.gz" still says which stage
 * failed without saying where the volume is.
 *
 * The roots are the same env vars JobWorkspace takes, so adding one means
 * adding it in both.
 */
final class ErrorRedactor
{
    /**
     * Long enough for any message the pipeline actually produces, short enough
     * that a pathological one cannot bloat an AMQP header or a callback body.
     */
    private const MAX_LENGTH = 500;

    private const TRUNCATION_MARKER = ' ...(truncated)';

    /** @var array<string, string> replacement => root, longest root first */
    private readonly array $roots;

    public function __construct(string $buildTempDir, string $publishedDir)
    {
        $roots = [
            rtrim($buildTempDir, '/') => '<build>',
            rtrim($publishedDir, '/') => '<published>',
        ];

        // Longest first, so a root nested inside another is replaced by its own
        // name rather than by its parent's.
        uksort($roots, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        $this->roots = $roots;
    }

    public function redact(string $message): string
    {
        // 1. Flatten control characters. tar prints one line per member, and a
        //    raw NUL is not legal in a JSON string -- nor is a newline welcome
        //    in the single-line log entry this also feeds.
        $message = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message);

        // 2. Name the roots we know. Done before the generic path collapse so
        //    the message keeps saying WHICH tree it failed in.
        foreach ($this->roots as $root => $replacement) {
            if ($root !== '') {
                $message = str_replace($root, $replacement, $message);
            }
        }

        // 3. Strip URL userinfo, BEFORE the path collapse below so the
        //    credentials cannot survive inside something that stopped looking
        //    like a URL. The download URL is echoed back in several messages;
        //    if the client embedded a token in it, we must not repeat it into a
        //    different endpoint's request log.
        $message = (string) preg_replace('#([a-z][a-z0-9+.-]*://)[^/@\s]*@#i', '$1', $message);

        // 4. Collapse any absolute path left over -- one rooted somewhere we
        //    have no name for. The lookbehind carries all three exclusions:
        //      \w  so the path half of https://host/a/b is left alone, that URL
        //          being the client's own and how they identify the job;
        //      :   so the scheme's own "//" is not treated as a path;
        //      /   so the second slash of "//" is not either;
        //      >   so the relative tail after a <build>/<published>/<failed>
        //          placeholder survives -- "<build>/.../content.tar.gz" is what
        //          makes the message still say which stage failed.
        $message = (string) preg_replace('#(?<![\w:/>])/(?:[\w.+@%-]+/)*[\w.+@%-]+#', '<path>', $message);

        $message = trim($message);

        if (mb_strlen($message) > self::MAX_LENGTH) {
            return mb_substr($message, 0, self::MAX_LENGTH) . self::TRUNCATION_MARKER;
        }

        return $message;
    }
}
