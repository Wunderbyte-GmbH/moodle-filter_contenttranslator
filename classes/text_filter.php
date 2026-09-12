<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace filter_contenttranslator;

use local_contenttranslator\api;
use local_contenttranslator\config;
use local_contenttranslator\normaliser;
use local_contenttranslator\translation_manager;

/**
 * Render-time filter: replaces registered content with its translation for the current language.
 *
 * Lookups are served from the MUC cache or one DB query; no engine is ever called here.
 *
 * @package    filter_contenttranslator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_filter extends \core_filters\text_filter {
    /** @var bool Whether the "show original" banner was already requested for this page */
    private static bool $bannerdone = false;

    /** @var bool|null Session toggle cache */
    private static ?bool $showoriginal = null;

    #[\Override]
    public function setup($page, $context) {
        global $SESSION;
        if (self::$showoriginal !== null || !config::get('showoriginaltoggle', 1)) {
            return;
        }
        $param = optional_param('ctoriginal', null, PARAM_INT);
        if ($param !== null) {
            $SESSION->filter_contenttranslator_original = (bool)$param;
        }
        self::$showoriginal = !empty($SESSION->filter_contenttranslator_original);
    }

    /**
     * Whether the user asked to see the source text.
     *
     * @return bool
     */
    public static function show_original(): bool {
        global $SESSION;
        if (self::$showoriginal === null) {
            self::$showoriginal = !empty($SESSION->filter_contenttranslator_original);
        }
        return self::$showoriginal;
    }

    /**
     * Reset static state (tests).
     */
    public static function reset(): void {
        self::$bannerdone = false;
        self::$showoriginal = null;
    }

    #[\Override]
    public function filter($text, array $options = []) {
        if (!is_string($text) || $text === '') {
            return $text;
        }
        $lang = current_language();
        $result = api::lookup($text, $lang, $this->context);
        if (!$result) {
            return $text;
        }
        if (!$result['found']) {
            // Known text without a visible translation: mark the source language for assistive technology.
            if (
                config::get('langattributes', 1) && $result['sourcelang'] !== $lang
                && strpos($result['sourcelang'], $lang) !== 0 && strpos($lang, $result['sourcelang']) !== 0
            ) {
                return \html_writer::div($text, 'ct-source', ['lang' => $result['sourcelang']]);
            }
            return $text;
        }
        $this->request_banner($result);
        if (self::show_original()) {
            return \html_writer::div($text, 'ct-source', ['lang' => $result['sourcelang']]);
        }
        $translation = normaliser::rewrite_file_urls((string)$result['text'], $text);
        if ((int)$result['format'] !== FORMAT_HTML) {
            $translation = format_text($translation, (int)$result['format'], ['filter' => false, 'context' => $this->context]);
        }
        $classes = 'ct-translated ct-' . $result['status'];
        $badge = '';
        $machine = $result['status'] !== translation_manager::STATUS_REVIEWED;
        if ($machine && config::get_badge_mode($result['lang']) === 'block') {
            $badge = \html_writer::span(
                get_string('machinetranslated', 'filter_contenttranslator'),
                'badge bg-light text-muted border ct-badge',
                ['title' => get_string('machinetranslated_desc', 'filter_contenttranslator')]
            );
        }
        return \html_writer::div($translation . $badge, $classes, ['lang' => $result['lang']]);
    }

    #[\Override]
    public function filter_stage_string(string $text, array $options): string {
        if ($text === '') {
            return $text;
        }
        $lang = current_language();
        $result = api::lookup($text, $lang, $this->context);
        if (!$result || !$result['found']) {
            return $text;
        }
        $this->request_banner($result);
        if (self::show_original()) {
            return $text;
        }
        // Output of format_string() must stay plain.
        return trim(strip_tags((string)$result['text']));
    }

    /**
     * Add the page level banner ("machine translated · show original") once per page.
     *
     * @param array $result
     */
    private function request_banner(array $result): void {
        global $PAGE;
        if (self::$bannerdone || !isset($PAGE) || !$PAGE->has_set_url() || CLI_SCRIPT || AJAX_SCRIPT || WS_SERVER) {
            return;
        }
        $machine = $result['status'] !== translation_manager::STATUS_REVIEWED;
        $mode = config::get_badge_mode($result['lang']);
        $toggle = (bool)config::get('showoriginaltoggle', 1);
        if ($mode !== 'banner' && !$toggle) {
            return;
        }
        self::$bannerdone = true;
        $parts = [];
        if ($mode === 'banner' && ($machine || self::show_original())) {
            $parts[] = \html_writer::span(self::show_original()
                ? get_string('showingoriginal', 'filter_contenttranslator')
                : get_string('machinetranslated_page', 'filter_contenttranslator'));
        }
        if ($toggle) {
            $url = new \moodle_url($PAGE->url, ['ctoriginal' => self::show_original() ? 0 : 1]);
            $parts[] = \html_writer::link($url, self::show_original()
                ? get_string('showtranslation', 'filter_contenttranslator')
                : get_string('showoriginal', 'filter_contenttranslator'), ['class' => 'ml-2 ms-2']);
        }
        if (!$parts) {
            return;
        }
        $html = \html_writer::div(
            implode(' ', $parts),
            'alert alert-light border small py-1 px-2 mb-2 filter-contenttranslator-banner',
            ['role' => 'status']
        );
        $PAGE->requires->js_amd_inline('
(function() {
    var target = document.getElementById("region-main") || document.getElementById("page-content") || document.body;
    var wrapper = document.createElement("div");
    wrapper.innerHTML = ' . json_encode($html) . ';
    target.insertBefore(wrapper.firstChild, target.firstChild);
})();');
    }
}
