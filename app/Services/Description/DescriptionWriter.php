<?php

namespace App\Services\Description;

/**
 * Drafts a business description from what the owner has already told us.
 *
 * Same split as Search\QueryInterpreter: DescriptionDraftService is the free,
 * local implementation built from templates. A model-backed writer can replace
 * it behind Services::descriptionWriter() without the form or the endpoint
 * changing, and must fall back to the templates when the model is slow or
 * unavailable.
 *
 * A draft is only ever a suggestion put into the editor. Nothing saves it but
 * the owner pressing Save.
 */
interface DescriptionWriter
{
    /**
     * @param array{
     *     type?:string, name?:string, category?:string, group?:string,
     *     city?:string, suburb?:string, customer_location?:string,
     *     service_areas?:list<string>, services?:list<string>, tags?:list<string>,
     *     features?:list<string>
     * } $facts
     *
     * @return string sanitised HTML, one or two paragraphs; '' when there is
     *                too little to write from (no category)
     */
    public function draft(array $facts): string;
}
