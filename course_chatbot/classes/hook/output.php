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

/**
 *  This file defines the output callbacks for the local_course_chatbot plugin.
 *
 * @package   local_course_chatbot
 * @copyright 2025, Tomás Pinto <morato.toms@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_chatbot\hook;

defined('MOODLE_INTERNAL') || die();

class output {
    /**
     * Hook callback for core\hook\output\before_standard_top_of_body_html_generation.
     * Adds the chatbot modal and button to course pages.
     *
     * @param \core\hook\output\before_standard_top_of_body_html_generation $hook
     */
    public static function before_standard_top_of_body_html_generation(
        \core\hook\output\before_standard_top_of_body_html_generation $hook
    ) {
        global $PAGE, $COURSE, $USER, $OUTPUT;
        
        // Only display on course pages
        if ($PAGE->pagelayout === 'course') {
            // Include JavaScript using relative path from web root
            $PAGE->requires->js(new \moodle_url('/local/course_chatbot/init.js'));

            $templatecontext = [
                'course_id' => $COURSE->id,
                'user_email' => $USER->email,
                'chatbot_id' => 1, // TODO: Hardcoded for now, can be made dynamic later
                'user_name' => $USER->firstname . ' ' . $USER->lastname
            ];

            $hook->add_html($OUTPUT->render_from_template('local_course_chatbot/chatbot_modal', $templatecontext));
        }
    }
}