<?php

declare(strict_types=1);

namespace Symfony\Component\Translation\Bridge\Phrase\Event;


use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\TranslatorBag;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * @author wicliff <wicliff.wolda@gmail.com>
 */
class PhraseReadEvent extends Event
{
    public function __construct(
        private TranslatorBag $bag
    ) {
    }

    public function getBag(): TranslatorBag
    {
        return $this->bag;
    }

    public function setBag(TranslatorBag $bag): void
    {
        $this->bag = $bag;
    }
}