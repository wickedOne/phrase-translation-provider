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

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Translation\Dumper\XliffFileDumper;
use Symfony\Component\Translation\Exception\UnsupportedSchemeException;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\Provider\AbstractProviderFactory;
use Symfony\Component\Translation\Provider\Dsn;
use Symfony\Component\Translation\Provider\ProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author wicliff <wicliff.wolda@gmail.com>
 */
class PhraseProviderFactory extends AbstractProviderFactory
{
    private const HOST = 'api.phrase.com';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly LoaderInterface $loader,
        private readonly XliffFileDumper $xliffFileDumper,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly string $defaultLocale,
    ) {
    }

    public function create(Dsn $dsn): ProviderInterface
    {
        if ('phrase' !== $dsn->getScheme()) {
            throw new UnsupportedSchemeException($dsn, 'phrase', $this->getSupportedSchemes());
        }

        $endpoint = 'default' === $dsn->getHost() ? self::HOST : $dsn->getHost();
        $endpoint .= $dsn->getPort() ? ':'.$dsn->getPort() : '';

        $client = $this->httpClient->withOptions([
            'base_uri' => 'https://'.$endpoint.'/v2/projects/'.$this->getUser($dsn).'/',
            'headers' => [
                'Authorization' => 'token '.$this->getPassword($dsn),
                'User-Agent' => $dsn->getRequiredOption('userAgent'),
            ],
        ]);

        return new PhraseProvider($client, $this->logger, $this->loader, $this->xliffFileDumper, $this->dispatcher, $this->defaultLocale, $endpoint);
    }

    protected function getSupportedSchemes(): array
    {
        return ['phrase'];
    }
}
