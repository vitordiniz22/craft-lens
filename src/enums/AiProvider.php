<?php

declare(strict_types=1);

namespace vitordiniz22\craftlens\enums;

enum AiProvider: string
{
    use EnumOptionsTrait;

    case OpenAi = 'openai';
    case Gemini = 'gemini';
    case Claude = 'claude';

    public function label(): string
    {
        return match ($this) {
            self::OpenAi => 'OpenAI',
            self::Gemini => 'Google Gemini',
            self::Claude => 'Claude',
        };
    }
}
