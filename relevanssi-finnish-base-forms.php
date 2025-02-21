<?php

/*
Plugin Name: Relevanssi Finnish Base Forms
Plugin URI: https://github.com/joppuyo/relevanssi-finnish-base-forms
Description: Relevanssi plugin to add Finnish base forms in search index
Version: 1.1.1
Author: Johannes Siipola
Author URI: https://siipo.la
Text Domain: relevanssi-finnish-base-forms
*/

defined('ABSPATH') or die('I wish I was using a real MVC framework');

if (!defined('RELEVANSSI_FINNISH_BASE_FORMS_API_URL')) {
    return;
}

if (!defined('RELEVANSSI_FINNISH_BASE_FORMS_API_KEY')) {
    define('RELEVANSSI_FINNISH_BASE_FORMS_API_KEY', '');
}

// Check if we are using local Composer
if (file_exists(__DIR__ . '/vendor')) {
    require 'vendor/autoload.php';
}

class Relevanssi_Finnish_Base_Forms {
    /**
     * Store lemmatized terms for highlighting
     * @var string[]
     */
    private array $lemmatized_terms = [];

    /**
     * Constructor - initialize hooks
     */
    public function __construct() {
        add_filter('relevanssi_post_content_before_tokenize', [$this, 'maybe_lemmatize_content'], 10, 2);
        add_filter('relevanssi_post_title_before_tokenize', [$this, 'maybe_lemmatize_content'], 10, 2);
        // add_filter('relevanssi_custom_field_value', [$this, 'maybe_lemmatize_custom_field_value'], 10, 3);
        add_filter('relevanssi_search_filters', [$this, 'maybe_lemmatize_search_parameters']);
        add_filter('relevanssi_highlight_regex', [$this, 'maybe_highlight_regex'], 10, 2);
    }

    /**
     * Maybe lemmatize content if language is Finnish
     * @param string $content
     * @param object|\WP_Post $post_object
     * @return string
     */
    public function maybe_lemmatize_content(string $content, mixed $post_object): string {
        if (!function_exists('pll_get_post_language') || pll_get_post_language($post_object->ID) !== 'fi') {
            return $content;
        }
        return $this->lemmatize($content);
    }

    /**
     * Maybe lemmatize search parameters if language is Finnish
     */
    public function maybe_lemmatize_search_parameters(array $parameters): array {
        if (!function_exists('pll_current_language') || pll_current_language() !== 'fi') {
            return $parameters;
        }
        $tokenized = $this->tokenize(strip_tags($parameters['q']));
        $this->lemmatized_terms = $this->web_api($tokenized, RELEVANSSI_FINNISH_BASE_FORMS_API_URL);

        // Filter out words that are already in the original content
        $this->lemmatized_terms = array_filter($this->lemmatized_terms, fn($word) => !in_array($word, $tokenized));

        $parameters['q'] = trim($parameters['q'] . ' ' . implode(' ', $this->lemmatized_terms));
        return $parameters;
    }

    /**
     * Maybe lemmatize custom field value if language is Finnish
     */
    public function maybe_lemmatize_custom_field_value(array $meta_value, string $meta_key, int $post_id) : array {
        if (!function_exists('pll_get_post_language') || pll_get_post_language($post_id) !== 'fi') {
            return [$meta_value];
        }
        return [$this->lemmatize($meta_value[0])];
    }

    /**
     * Maybe highlight regex
     */
    public function maybe_highlight_regex(string $regex, string $term) : string {
        if (!empty($this->lemmatized_terms)) {
            $terms_pattern = "(?:" . $term . "|" . implode("|", $this->lemmatized_terms) . ")";
            $regex = '/([\w]*' . $terms_pattern . '[\W]|[\W]' . $terms_pattern . '[\w]*)/iu';
        }
        return $regex;
    }

    /**
     * Append lemmatized words to the original text
     * @throws Exception
     */
    private function lemmatize(string $content): string {
        if (!is_string($content) || empty($content) || is_numeric($content)) {
            return $content;
        }

        $tokenized = $this->tokenize(strip_tags($content));
        $extra_words = $this->web_api($tokenized, RELEVANSSI_FINNISH_BASE_FORMS_API_URL);

        // Filter out words that are already in the original content
        $extra_words = array_filter($extra_words, fn($word) => !in_array($word, $tokenized));

        return trim($content . ' ' . implode(' ', $extra_words));
    }

    /**
     * Simple white space tokenizer. Breaks either on whitespace or on word
     * boundaries (ex.: dots, commas, etc) Does not include white space or
     * punctuations in tokens.
     *
     * Based on NlpTools (http://php-nlp-tools.com/) under WTFPL license.
     *
     * @param string $str
     * @return string[]
     */
    private function tokenize(string $str): array {
        $arr = [];
        // for the character classes
        // see http://php.net/manual/en/regexp.reference.unicode.php
        $pat
            = '/
                ([\pZ\pC]*)       # match any separator or other
                                  # in sequence
                (
                    [^\pP\pZ\pC]+ # match a sequence of characters
                                  # that are not punctuation,
                                  # separator or other
                )
                ([\pZ\pC]*)       # match a sequence of separators
                                  # that follows
            /xu';
        preg_match_all($pat, $str, $arr);

        return $arr[2];
    }

    /**
     * @param string[] $tokenized
     * @return string[]
     */
    private function web_api(array $tokenized, string $api_root): array {
        $headers = [];

        if (RELEVANSSI_FINNISH_BASE_FORMS_API_KEY) {
            $headers['Ocp-Apim-Subscription-Key'] = RELEVANSSI_FINNISH_BASE_FORMS_API_KEY;
        }

        $client = new \GuzzleHttp\Client([
            'headers' => $headers
        ]);

        $extra_words = [];

        $requests = function () use ($client, $tokenized, $api_root) {
            foreach ($tokenized as $token) {
                yield function () use ($client, $token, $api_root) {
                    return $client->getAsync(trailingslashit($api_root) . 'lemmatize/' . $token);
                };
            }
        };

        $pool = new \GuzzleHttp\Pool($client, $requests(), [
            'concurrency' => 10,
            'fulfilled' => function ($response) use (&$extra_words) {
                $baseform = json_decode($response->getBody()->getContents(), true);
                if ($baseform) {
                    $extra_words[] = $baseform;
                    $extra_words = array_unique($extra_words);
                }
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();

        return $extra_words;
    }
}

new Relevanssi_Finnish_Base_Forms();
