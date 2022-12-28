<?php

declare(strict_types=1);

/*
 * This file is part of the Phrase Symfony Translation Provider.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Translation\Bridge\Phrase;

use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Component\Translation\Bridge\Phrase\Cache\PhraseCachedResponse;
use Symfony\Component\Translation\Bridge\Phrase\Event\PhraseReadEvent;
use Symfony\Component\Translation\Bridge\Phrase\Event\PhraseWriteEvent;
use Symfony\Component\Translation\Dumper\XliffFileDumper;
use Symfony\Component\Translation\Exception\ProviderException;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Provider\ProviderInterface;
use Symfony\Component\Translation\TranslatorBag;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @author wicliff <wicliff.wolda@gmail.com>
 */
class PhraseProvider implements ProviderInterface
{
    /**
     * @var array<string, string>
     */
    private static array $phraseLocales = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly LoaderInterface $loader,
        private readonly XliffFileDumper $xliffFileDumper,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $defaultLocale,
        private readonly string $endpoint,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('phrase://%s', $this->endpoint);
    }

    /**
     * @param array<array-key, string> $domains
     * @param array<array-key, string> $locales
     */
    public function read(array $domains, array $locales): TranslatorBag
    {
        $translatorBag = new TranslatorBag();

        foreach ($locales as $locale) {
            $phraseLocale = $this->getLocale($locale);

            foreach ($domains as $domain) {
                $item = $this->cache->getItem($phraseLocale.'.'.$domain);
                $headers = $item->isHit() ? ['If-None-Match' => $item->get()->getEtag()] : [];

                $response = $this->httpClient->request('GET', 'locales/'.$phraseLocale.'/download', [
                    'query' => [
                        'file_format' => 'symfony_xliff',
                        'tags' => $domain,
                        'format_options' => ['enclose_in_cdata'],
                        'include_empty_translations' => true,
                    ],
                    'headers' => $headers,
                ]);

                if (200 !== ($statusCode = $response->getStatusCode()) && 304 !== $statusCode) {
                    $this->logger->error(sprintf('Unable to get translations for locale "%s" from phrase: "%s".', $locale, $response->getContent(false)));

                    $this->throwProviderException($statusCode, $response, 'Unable to get translations from phrase.');
                }

                $content = 304 === $statusCode ? $item->get()->getContent() : $response->getContent();
                $translatorBag->addCatalogue($this->loader->load($content, $locale, $domain));

                $headers = $response->getHeaders(false);
                $item->set(new PhraseCachedResponse($headers['etag'][0], $headers['last-modified'][0], $content));

                $this->cache->save($item);
            }
        }

        $event = new PhraseReadEvent($translatorBag);
        $this->dispatcher->dispatch($event);

        return $event->getBag();
    }

    /**
     * @param \Symfony\Component\Translation\TranslatorBag $translatorBag
     */
    public function write(TranslatorBagInterface $translatorBag): void
    {
        $event = new PhraseWriteEvent($translatorBag);
        $this->dispatcher->dispatch($event);

        /** @var MessageCatalogue[] $catalogues */
        $catalogues = $event->getBag()->getCatalogues();

        foreach ($catalogues as $catalogue) {
            foreach ($catalogue->getDomains() as $domain) {
                if (0 === \count($catalogue->all($domain))) {
                    continue;
                }

                $phraseLocale = $this->getLocale($catalogue->getLocale());

                $content = $this->xliffFileDumper->formatCatalogue($catalogue, $domain, ['default_locale' => $this->defaultLocale]);
                $filename = sprintf('%d-%s-%s.xlf', date('YmdHis'), $domain, $catalogue->getLocale());

                $fields = [
                    'file_format' => 'symfony_xliff',
                    'file' => new DataPart($content, $filename, 'application/xml'),
                    'locale_id' => $phraseLocale,
                    'tags' => $domain,
                    'update_translations' => '1',
                ];

                $formData = new FormDataPart($fields);

                $response = $this->httpClient->request('POST', 'uploads', [
                    'body' => $formData->bodyToIterable(),
                    'headers' => $formData->getPreparedHeaders()->toArray(),
                ]);

                if (201 !== $statusCode = $response->getStatusCode()) {
                    $this->logger->error(sprintf('Unable to upload translations for domain "%s" to phrase: "%s".', $domain, $response->getContent(false)));

                    $this->throwProviderException($statusCode, $response, 'Unable to upload translations to phrase.');
                }
            }
        }
    }

    public function delete(TranslatorBagInterface $translatorBag): void
    {
        $defaultCatalogue = $translatorBag->getCatalogue($this->defaultLocale);

        foreach ($defaultCatalogue->getDomains() as $domain) {
            if ([] === $keys = array_keys($defaultCatalogue->all($domain))) {
                continue;
            }

            $names = array_map(static fn ($v): ?string => preg_replace('/([\s:,])/', '\\\\\\\\$1', $v), $keys);

            foreach ($names as $name) {
                $response = $this->httpClient->request('DELETE', 'keys', [
                    'query' => [
                        'q' => 'name:'.$name,
                    ],
                ]);

                if (200 !== $statusCode = $response->getStatusCode()) {
                    $this->logger->error(sprintf('Unable to delete key "%s" in phrase: "%s".', $name, $response->getContent(false)));

                    $this->throwProviderException($statusCode, $response, 'Unable to delete key in phrase.');
                }
            }
        }
    }

    public static function resetPhraseLocales(): void
    {
        self::$phraseLocales = [];
    }

    private function getLocale(string $locale): string
    {
        if ([] === self::$phraseLocales) {
            $this->initLocales();
        }

        if (!\array_key_exists($locale, self::$phraseLocales)) {
            $this->createLocale($locale);
        }

        return self::$phraseLocales[$locale];
    }

    private function createLocale(string $locale): void
    {
        $response = $this->httpClient->request('POST', 'locales', [
            'body' => [
                'name' => $locale,
                'code' => $this->toPhraseLocale($locale),
            ],
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);

        if (201 !== $statusCode = $response->getStatusCode()) {
            $this->logger->error(sprintf('Unable to create locale "%s" in phrase: "%s".', $locale, $response->getContent(false)));

            $this->throwProviderException($statusCode, $response, 'Unable to create locale phrase.');
        }

        $phraseLocale = $response->toArray();

        self::$phraseLocales[$this->toSymfonyLocale($phraseLocale['code'])] = $phraseLocale['id'];
    }

    private function initLocales(): void
    {
        $response = $this->httpClient->request('GET', 'locales');

        if (200 !== $statusCode = $response->getStatusCode()) {
            $this->logger->error(sprintf('Unable to get locales from phrase: "%s".', $response->getContent(false)));

            $this->throwProviderException($statusCode, $response, 'Unable to get locales from phrase.');
        }

        foreach ($response->toArray() as $phraseLocale) {
            self::$phraseLocales[$this->toSymfonyLocale($phraseLocale['code'])] = $phraseLocale['id'];
        }
    }

    private function throwProviderException(int $statusCode, ResponseInterface $response, string $message): void
    {
        $headers = $response->getHeaders(false);

        throw match (true) {
            429 === $statusCode => new ProviderException(sprintf('Rate limit exceeded (%s). please wait %s seconds.',
                $headers['x-rate-limit-limit'][0],
                $headers['x-rate-limit-reset'][0]
            ), $response),
            $statusCode <= 500 => new ProviderException($message, $response),
            default => new ProviderException('Provider server error.', $response),
        };
    }

    private function toSymfonyLocale(string $locale): string
    {
        return str_replace('-', '_', $locale);
    }

    private function toPhraseLocale(string $locale): string
    {
        return str_replace('_', '-', $locale);
    }
}
