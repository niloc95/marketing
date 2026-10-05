<?php

namespace App\Services\Search;

/**
 * Turns what someone typed into what they meant.
 *
 * The interpreter only ever describes the search. It never finds businesses:
 * DirectoryService does that from our own data, whatever produced the intent.
 * That split is the whole design. RuleBasedInterpreter is the free, local
 * implementation; a model-backed one (Claude Haiku) can replace it behind
 * Services::queryInterpreter() without anything downstream changing, and must
 * fall back to the rules when the model is slow or unavailable.
 */
interface QueryInterpreter
{
    public function interpret(string $q): SearchIntent;
}
