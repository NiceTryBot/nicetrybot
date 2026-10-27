<?php

namespace App\Support;

use App\Models\Domain;
use Illuminate\Support\Arr;

/**
 * Makes up the playful stand-in each domain gets as it's created (domains.privacy_hostname), like
 * invisible-pony.hidden-domain.com. Every domain has one, so an add-on that redacts the page for
 * screen sharing can show it in place of the real host.
 */
class PrivacyHostname
{
    /**
     * Every stand-in lives under this made-up zone.
     */
    public const string SUFFIX = 'hidden-domain.com';

    /**
     * @var list<string>
     */
    public const array ADJECTIVES = [
        'invisible', 'sneaky', 'quiet', 'masked', 'secret', 'shy', 'hushed', 'cloaked',
        'velvet', 'misty', 'shadowy', 'humble', 'sleepy', 'polite', 'tiny', 'fuzzy',
        'bashful', 'covert', 'dreamy', 'gentle', 'hidden', 'incognito', 'low-key', 'mellow',
        'mysterious', 'nimble', 'private', 'silent', 'soft', 'stealthy', 'subtle', 'whispering',
        'anonymous', 'bouncy', 'cosmic', 'curious', 'dapper', 'fancy', 'giggly', 'jolly',
        'lucky', 'merry', 'plucky', 'quirky', 'snug', 'sparkly', 'spry', 'wobbly',
    ];

    /**
     * @var list<string>
     */
    public const array NOUNS = [
        'pony', 'otter', 'panda', 'walrus', 'narwhal', 'badger', 'ferret', 'llama',
        'penguin', 'koala', 'hedgehog', 'raccoon', 'octopus', 'platypus', 'sloth', 'yak',
        'alpaca', 'beaver', 'capybara', 'dingo', 'emu', 'gecko', 'hamster', 'ibis',
        'jackalope', 'kiwi', 'lemur', 'marmot', 'newt', 'ocelot', 'puffin', 'quokka',
        'squirrel', 'tapir', 'unicorn', 'vole', 'wombat', 'yeti', 'zebra', 'mongoose',
        'pangolin', 'axolotl', 'chinchilla', 'dormouse', 'flamingo', 'gopher', 'manatee', 'moose',
    ];

    /**
     * Pick a stand-in no other domain wears, adding a number once the word pairs run thin.
     */
    public static function generate(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $label = Arr::random(self::ADJECTIVES).'-'.Arr::random(self::NOUNS);

            if ($attempt >= 10) {
                $label .= '-'.random_int(2, 999);
            }

            $hostname = $label.'.'.self::SUFFIX;

            if (! Domain::query()->where('privacy_hostname', $hostname)->exists()) {
                return $hostname;
            }
        }

        return Arr::random(self::ADJECTIVES).'-'.Arr::random(self::NOUNS).'-'.bin2hex(random_bytes(4)).'.'.self::SUFFIX;
    }
}
