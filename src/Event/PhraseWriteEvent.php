<?php

declare(strict_types=1);

namespace Symfony\Component\Translation\Bridge\Phrase\Event;


use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\TranslatorBag;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * @author wicliff <wicliff.wolda@gmail.com>
 */
class PhraseWriteEvent extends Event
{
    public function __construct(
        private TranslatorBagInterface $bag
    ) {
    }

    public function getBag(): TranslatorBagInterface
    {
        return $this->bag;
    }

    public function setBag(TranslatorBagInterface $bag): void
    {
        $this->bag = $bag;
    }
}