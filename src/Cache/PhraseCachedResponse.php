<?php

declare(strict_types=1);

/*
 * This file is part of the Phrase Symfony Translation Provider.
 * (c) wicliff <wicliff.wolda@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Translation\Bridge\Phrase\Cache;

/**
 * @author wicliff <wicliff.wolda@gmail.com>
 */
class PhraseCachedResponse
{
    public function __construct(
        private readonly string $etag,
        private readonly string $modified,
        private readonly string $content,
    ) {
    }

    public function getEtag(): string
    {
        return $this->etag;
    }

    public function getModified(): string
    {
        return $this->modified;
    }

    public function getContent(): string
    {
        return $this->content;
    }
}
