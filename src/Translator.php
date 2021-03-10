<?php

declare(strict_types=1);

namespace Baraja\TranslatorBot;


interface Translator
{
	public function translate(string $haystack, string $sourceLocale, string $targetLocale): string;
}
