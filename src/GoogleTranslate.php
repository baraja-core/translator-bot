<?php

declare(strict_types=1);

namespace Baraja\TranslatorBot;


final class GoogleTranslate implements Translator
{
	public function translate(string $haystack, string $sourceLocale, string $targetLocale): string
	{
		$tr = new \Stichoza\GoogleTranslate\GoogleTranslate;
		$tr->setSource($sourceLocale);
		$tr->setTarget($targetLocale);

		return $tr->translate($haystack);
	}
}
