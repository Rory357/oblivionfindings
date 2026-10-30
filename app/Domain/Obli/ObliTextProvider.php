<?php

namespace App\Domain\Obli;

interface ObliTextProvider
{
    /** @return array{text:string,input_tokens:int,output_tokens:int} */
    public function respond(#[\SensitiveParameter] array $configuration, #[\SensitiveParameter] array $messages): array;
}
