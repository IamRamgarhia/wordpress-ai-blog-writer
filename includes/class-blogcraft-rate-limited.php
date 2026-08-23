<?php
/**
 * The provider asking us to come back later.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a provider refuses a request because of a rate or quota limit.
 *
 * A rate limit is not a failure, it is a wait — and the difference matters,
 * because a job that treats it as a failure burns all three of its attempts
 * inside a couple of minutes against a quota that resets in hours, throwing
 * away an article it had nearly finished paying for.
 *
 * This exists as a type so that difference can be recognised by catching it.
 * It used to be recognised by searching the error message for "HTTP 429" and
 * for OpenAI's particular phrasing of "exceeded your current quota" — both of
 * which are assembled from translated format strings, so on a site running in
 * any other language the deferral quietly stopped happening and every rate
 * limit spent an attempt instead.
 */
class Blogcraft_Rate_Limited extends RuntimeException {

}
