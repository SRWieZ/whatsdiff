<?php

namespace Whatsdiff\Parsers;

use Laravel\Prompts\Concerns\Colors;

class MarkdownToConsole
{
    use Colors;

    public function __construct(
    ) {

    }

    public function parseMarkdown($markdown): array
    {
        $lines = explode("\n", $markdown);
        $convertedLines = [];

        foreach ($lines as $line) {
            $convertedLines[] = $this->convertInline($line);
        }

        return $convertedLines;
    }

    public function convertInline(string $markdown): string
    {
        // Convert link to terminal link
        $markdown = preg_replace_callback(
            '/\[(.*?)\]\((.*?)\)/',
            fn ($matches) => $this->link($matches[1], $matches[2]),
            $markdown
        );

        // Convert heading 1
        $markdown = preg_replace_callback(
            '/^(#\s.*)/m',
            fn ($matches) => $this->bgCyan($this->bold($this->gray($matches[1]))),
            $markdown
        );

        // Convert heading 2
        $markdown = preg_replace_callback(
            '/^(##\s.*)/m',
            fn ($matches) => $this->bgBlue($matches[1]),
            $markdown
        );


        // // Convert bold
        // $markdown = preg_replace('/\*\*(.*?)\*\*/', "\033[1m$1\033[0m", $markdown);
        // // Convert italic
        // $markdown = preg_replace('/\*(.*?)\*/', "\033[3m$1\033[0m", $markdown);
        // // Convert strikethrough
        // $markdown = preg_replace('/\[(.*?)\]\((.*?)\)/', "\033[4m$1\033[0m ($2)", $markdown);
        // // Convert inline code
        // $markdown = preg_replace('/`(.*?)`/', "\033[7m$1\033[0m", $markdown);
        // // Convert code blocks
        // $markdown = preg_replace('/```(.*?)```/s', "\033[7m$1\033[0m", $markdown);
        // // Convert links
        // $markdown = preg_replace('/\[(.*?)\]\((.*?)\)/', "\033[4m$1\033[0m ($2)", $markdown);
        // // Convert images
        // $markdown = preg_replace('/!\[(.*?)\]\((.*?)\)/', "\033[4m$1\033[0m ($2)", $markdown);
        // // Convert blockquotes
        // $markdown = preg_replace('/\>(.*?)\n/', "\033[2m$1\033[0m\n", $markdown);
        // // Convert lists
        // $markdown = preg_replace('/\*\s(.*?)\n/', "\033[2m- $1\033[0m\n", $markdown);
        // // Convert headings
        // $markdown = preg_replace('/\#\s(.*?)\n/', "\033[1m$1\033[0m\n", $markdown);
        // // Convert horizontal rules
        // $markdown = preg_replace('/\-\-\-\-\-\n/', "\033[2m----------------\033[0m\n", $markdown);
        // // Convert tables
        // $markdown = preg_replace('/\| (.*?) \|/', "\033[2m$1\033[0m", $markdown);
        // // Convert block elements
        // $markdown = preg_replace('/\>\>(.*?)\n/', "\033[2m$1\033[0m\n", $markdown);
        // // Convert footnotes
        // $markdown = preg_replace('/\[(.*?)\]: (.*?)/', "\033[2m$1\033[0m: $2", $markdown);
        // // Convert task lists
        // $markdown = preg_replace('/\-\s\[(.*?)\]\s(.*?)/', "\033[2m- [$1] $2\033[0m", $markdown);
        // // Convert emojis
        // $markdown = preg_replace('/\:(.*?)\:/', "\033[2m:$1:\033[0m", $markdown);
        // // Convert HTML tags
        // $markdown = preg_replace('/\<(.*?)\>/', "\033[2m$1\033[0m", $markdown);

        return $markdown;
    }

    private function link(mixed $text, mixed $link)
    {
        // ray($text, $link, "\e]8;;$link\007$text\e]8;;\007");
        return "\e]8;;$link\007$text\e]8;;\007";
    }
}
