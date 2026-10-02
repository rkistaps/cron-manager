<?php

declare(strict_types=1);

namespace rkistaps\CronManager\Structures;

final readonly class CrontabBlock
{
    private const string MARKER = 'cron-manager:';

    public string $checksum;

    /**
     * @param list<string> $lines    The body: every line between the BEGIN and END markers
     * @param string|null  $checksum The checksum from the BEGIN marker of a parsed block. Computed when null.
     */
    public function __construct(
        public string $appId,
        public array $lines,
        ?string $checksum = null,
    ) {
        $this->checksum = $checksum ?? self::checksumOf($lines);
    }

    /**
     * First 12 hex characters of the sha256 of the body, each line followed by "\n"
     *
     * @param list<string> $lines
     */
    public static function checksumOf(array $lines): string
    {
        $body = implode('', array_map(static fn (string $line): string => $line . "\n", $lines));

        return substr(hash('sha256', $body), 0, 12);
    }

    public static function beginMarkerPrefix(string $appId): string
    {
        return '# BEGIN ' . self::MARKER . $appId;
    }

    public static function endMarkerFor(string $appId): string
    {
        return '# END ' . self::MARKER . $appId;
    }

    public function isHandEdited(): bool
    {
        return $this->checksum !== self::checksumOf($this->lines);
    }

    public function beginMarker(): string
    {
        return self::beginMarkerPrefix($this->appId) . ' sha=' . $this->checksum;
    }

    public function endMarker(): string
    {
        return self::endMarkerFor($this->appId);
    }

    /**
     * @return list<string> The markers and the body
     */
    public function allLines(): array
    {
        return [$this->beginMarker(), ...$this->lines, $this->endMarker()];
    }

    public function render(): string
    {
        return implode("\n", $this->allLines()) . "\n";
    }
}
