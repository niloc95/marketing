<?php

namespace App\Services\Search;

/**
 * The words the interpreter can recognise, built from our own data:
 * DirectoryService::searchVocabulary() fills it from the database, tests build
 * it by hand. Every key is in the interpreter's normalised form (see
 * RuleBasedInterpreter::normalise()).
 */
final class SearchVocabulary
{
    /**
     * @param array<string,array{slug:string,name:string}> $categories phrase => category
     * @param array<string,array{slug:string,name:string}> $groups     phrase => main category
     * @param array<string,array{name:string,kind:string}> $places     phrase => place; kind is
     *                                                                'city' or 'suburb'
     * @param array<string,true>                           $serviceWords single words found in
     *                                                                services and tags
     */
    public function __construct(
        public readonly array $categories = [],
        public readonly array $groups = [],
        public readonly array $places = [],
        public readonly array $serviceWords = [],
    ) {
    }
}
