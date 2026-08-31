<?php
/**
 * The one place markup built in pieces is allowed out.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filters assembled markup through a fixed allowlist before it is printed.
 *
 * Nine places in this plugin built a fragment of HTML in a helper, escaped
 * every value going into it, and then echoed the result behind a
 * phpcs:ignore saying so. Each comment was accurate. That is not the
 * problem with them.
 *
 * The problem is that the safety was an assertion rather than a mechanism.
 * It held only for as long as every helper stayed correct, it had to be
 * re-verified by reading whenever one changed, and a reviewer has no way to
 * tell a true claim from a false one without doing that reading themselves.
 * Which is why "echo $html; // safe" is the pattern that draws the most
 * suspicion in review, and deserves to.
 *
 * wp_kses() turns the claim into a rule. The tag list below is what these
 * fragments actually contain; anything else is dropped rather than trusted,
 * so a helper that starts emitting something unexpected produces visibly
 * missing markup instead of a silent hole.
 */
class Blogcraft_Markup {

	/**
	 * Tags and attributes the plugin's own fragments are built from.
	 *
	 * Deliberately narrow. It is not wp_kses_post(): that permits far more
	 * than anything here emits, including iframes and objects, and a list
	 * wider than the need is a list that stops meaning anything.
	 *
	 * @return array Tag => allowed attributes, for wp_kses().
	 */
	public static function allowed() {
		// The accessibility attributes are not decoration. aria-current is
		// how the navigation tells a screen reader which page you are on, and
		// the first version of this list dropped it silently from every
		// screen — which is the exact failure a too-narrow allowlist causes,
		// and the reason the fixtures below are checked byte for byte.
		$common = array(
			'class'            => true,
			'id'               => true,
			'style'            => true,
			'title'            => true,
			'role'             => true,
			'aria-label'       => true,
			'aria-labelledby'  => true,
			'aria-describedby' => true,
			'aria-current'     => true,
			'aria-controls'    => true,
			'aria-expanded'    => true,
			'aria-hidden'      => true,
			'aria-live'        => true,
			'data-*'           => true,
		);

		return array(
			'a'        => array_merge(
				$common,
				array(
					'href'   => true,
					'rel'    => true,
					'target' => true,
				)
			),
			'span'     => $common,
			'div'      => $common,
			'p'        => $common,
			'label'    => array_merge( $common, array( 'for' => true ) ),
			'strong'   => $common,
			'em'       => $common,
			'code'     => $common,
			'br'       => array(),
			'small'    => $common,
			'input'    => array_merge(
				$common,
				array(
					'type'         => true,
					'name'         => true,
					'value'        => true,
					'checked'      => true,
					'placeholder'  => true,
					'min'          => true,
					'max'          => true,
					'step'         => true,
					'disabled'     => true,
					'readonly'     => true,
					'autocomplete' => true,
				)
			),
			'button'   => array_merge(
				$common,
				array(
					'type'     => true,
					'name'     => true,
					'value'    => true,
					'form'     => true,
					'disabled' => true,
				)
			),
			'select'   => array_merge(
				$common,
				array(
					'name'     => true,
					'multiple' => true,
					'disabled' => true,
				)
			),
			'option'   => array_merge(
				$common,
				array(
					'value'    => true,
					'selected' => true,
					'disabled' => true,
				)
			),
			'optgroup' => array_merge( $common, array( 'label' => true ) ),
			'textarea' => array_merge(
				$common,
				array(
					'name'        => true,
					'rows'        => true,
					'cols'        => true,
					'placeholder' => true,
				)
			),
			'ul'       => $common,
			'ol'       => $common,
			'li'       => $common,
		);
	}
}
