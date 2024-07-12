<?php

namespace Swerve\Util;

use Charm\Terminal;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;

class Logger implements LoggerInterface
{
    use LoggerTrait;

    private const COLORS = [
        LogLevel::DEBUG => '<!silverBG black>',
        LogLevel::INFO => '<!tealBG black>',
        LogLevel::NOTICE => '<!fuchsia black>',
        LogLevel::WARNING => '<!maroonBG black>',
        LogLevel::ERROR => '<!redBG black>',
        LogLevel::CRITICAL => '<!redBG white>',
        LogLevel::ALERT => '<!redBG white>',
        LogLevel::EMERGENCY => '<!redBG white>',
    ];

    private string $source;
    private array $logLevels = [
        LogLevel::DEBUG => true,
        LogLevel::INFO => true,
        LogLevel::NOTICE => true,
        LogLevel::WARNING => true,
        LogLevel::ERROR => true,
        LogLevel::CRITICAL => true,
        LogLevel::ALERT => true,
        LogLevel::EMERGENCY => true,
    ];
    private Terminal $term;

    public function __construct(Terminal $term, ?string $source = null, string $logLevel = LogLevel::DEBUG)
    {
        $this->term = $term;
        $this->source = $source !== null ? \str_pad($source, 10) : '';
        foreach ($this->logLevels as $level => $state) {
            if ($logLevel === $level) {
                break;
            }
            $this->logLevels[$level] = false;
        }
    }

    public function withSource(string $source): Logger
    {
        $c = clone $this;
        $c->source = $source;

        return $c;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (empty($this->logLevels[$level])) {
            return;
        }
        $replace = [];
        foreach ($context as $key => $val) {
            if (!\is_array($val) && (!\is_object($val) || \method_exists($val, '__toString'))) {
                $replace['{'.$key.'}'] = '<!underline>'.$val.'<!>';
            }
        }

        $message = \strtr($message, $replace);
        $this->term->write('<!white>'.\gmdate('Y-m-d H:i:s').'<!> '.$this->source.Terminal::str_pad(self::COLORS[$level].$level.'<!>', 12, ' ', \STR_PAD_BOTH).$message."\n");
    }
}
