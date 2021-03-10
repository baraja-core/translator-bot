<?php

declare(strict_types=1);

namespace Baraja\TranslatorBot;


use Baraja\Doctrine\EntityManager;
use Baraja\Localization\Localization;
use Baraja\Localization\Translation;
use Nette\Utils\Strings;

final class TranslatorBot
{
	private Translator $translator;

	private int $sleepBeforeTranslate = 1;


	public function __construct(
		private EntityManager $entityManager,
		private Localization $localization,
		?Translator $translator = null
	) {
		$this->translator = $translator ?? new GoogleTranslate;
	}


	public function translate(string $haystack, string $sourceLocale, string $targetLocale): string
	{
		if ($this->sleepBeforeTranslate > 0) {
			sleep($this->sleepBeforeTranslate);
		}

		return $this->normalizeResponse($this->translator->translate($haystack, $sourceLocale, $targetLocale));
	}


	/**
	 * @param string[] $entities
	 */
	public function process(array $entities, int $limit = 3, int $step = 32): void
	{
		$map = $this->mapSelector($entities);

		$iterator = 0;
		$masterCount = 0;
		do {
			$candidatesCount = 0;
			foreach ($this->selectEntities($map, $step, $step * ($iterator++)) as $entityClass => $candidates) {
				$fields = $map[$entityClass] ?? [];
				foreach ($candidates as $candidate) {
					$this->translateEntity($candidate, $fields);
					$candidatesCount++;
					if ((++$masterCount) >= $limit) {
						break 3;
					}
				}
			}
			$this->entityManager->flush();
			$this->entityManager->clear();
		} while ($candidatesCount > 0 && $iterator < 100);

		$this->entityManager->flush();
		$this->entityManager->clear();
	}


	/**
	 * @param string[] $fields
	 */
	private function translateEntity(object $entity, array $fields): void
	{
		$defaultLocale = $this->localization->getDefaultLocale();
		$tryLocales = array_diff(
			$this->localization->getAvailableLocales(),
			[$this->localization->getDefaultLocale()]
		);

		foreach ($fields as $field) {
			$translation = null;
			try {
				$ref = new \ReflectionProperty($entity, $field);
			} catch (\ReflectionException) {
				continue; // Silence is golden.
			}
			$ref->setAccessible(true);
			$translation = $ref->getValue($entity);
			if (is_string($translation)) {
				$translation = new Translation($translation, $defaultLocale);
			} elseif (!$translation instanceof Translation) {
				continue;
			}
			if ($translation !== null) {
				foreach ($tryLocales as $targetLocale) {
					$defaultContent = $translation->getTranslation($defaultLocale) ?: null;
					if ($defaultContent === null || $defaultContent === '#NO_DATA#') {
						continue; // Skip field without default content
					}
					$tempValue = $translation->getTranslation($targetLocale, false) ?: null;
					if ($tempValue === null || $tempValue === '#NO_DATA#') {
						$translation->addTranslate(
							$this->translate($defaultContent, $defaultLocale, $targetLocale),
							$targetLocale
						);
					}
				}
				$ref->setValue($entity, $translation->regenerate());
			}
		}
	}


	/**
	 * @param string[] $entities
	 * @return string[][]
	 */
	private function mapSelector(array $entities): array
	{
		$return = [];
		foreach ($entities as $entity) {
			$translationFields = [];
			foreach ($this->entityManager->getClassMetadata($entity)->fieldMappings as $field => $configuration) {
				if (($configuration['type'] ?? 'string') === 'translate') {
					$translationFields[] = $field;
				}
			}
			if ($translationFields === []) {
				throw new \InvalidArgumentException(
					'There is no translatable field in the "' . $entity . '" entity. '
					. 'Are you using the Doctrine Localization type extension (package "baraja-core/localization")?',
				);
			}
			$return[$entity] = $translationFields;
		}
		if ($return === []) {
			throw new \InvalidArgumentException('There is no translatable entity.');
		}

		return $return;
	}


	/**
	 * @param mixed[][] $map
	 * @return object[][]
	 */
	private function selectEntities(array $map, int $limit, int $offset = 0): array
	{
		$tryLocales = array_diff(
			$this->localization->getAvailableLocales(),
			[$this->localization->getDefaultLocale()]
		);

		$return = [];
		foreach ($map as $entity => $fields) {
			$selector = $this->entityManager->getRepository($entity)
				->createQueryBuilder('e')
				->select('PARTIAL e.{id, ' . implode(', ', $fields) . '}');

			foreach ($tryLocales as $locale) {
				$conditions = [];
				foreach ($fields as $field) {
					$conditions[] = 'e.' . $field . ' NOT LIKE :locale_' . $locale;
				}
				$selector->orWhere(implode(' OR ', $conditions));
				$selector->setParameter('locale_' . $locale, '%"' . $locale . '"%');
			}

			$return[$entity] = $selector
				->orderBy('e.id', 'ASC')
				->setMaxResults($limit)
				->setFirstResult($offset)
				->getQuery()
				->getResult();
		}

		return $return;
	}


	private function normalizeResponse(string $haystack): string
	{
		$return = [];
		foreach (explode("\n", trim(Strings::normalize($haystack))) as $line) {
			$line = trim($line);
			$line = (string) preg_replace('/\s*<\s*(\/?)\s*([a-zA-Z-_]+\d*)\s*>\s*/', '<$1$2>', $line);
			$line = (string) preg_replace('/(\*{2,})\s*([^*]+?)\s*(\*{2,})/', '$1$2$3', $line);
			$return[] = $line;
		}

		return trim(implode("\n", $return));
	}
}
