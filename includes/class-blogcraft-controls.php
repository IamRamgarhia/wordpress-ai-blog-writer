<?php
/**
 * Shared form controls.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * The control vocabulary both the blueprint editor and the composer use.
 *
 * Kept in one place because the two screens edit the same fields, and two
 * implementations of a toggle is how a segmented control ends up looking one
 * way on one screen and another way on the next.
 *
 * Every renderer returns markup rather than echoing it, so callers can wrap or
 * discard it without output buffering.
 */
class Blogcraft_Controls {

	/**
	 * A field row: label, control, and an optional hint beneath.
	 *
	 * @param string $label     Field label.
	 * @param string $hint      Explanation beneath the control.
	 * @param string $control   Rendered control markup.
	 * @param string $label_for Id the label points at, if any.
	 * @return string
	 */
	public static function row( $label, $hint, $control, $label_for = '' ) {
		$head = ( '' === $label_for )
			? sprintf( '<span class="bc-label">%s</span>', esc_html( $label ) )
			: sprintf( '<label class="bc-label" for="%1$s">%2$s</label>', esc_attr( $label_for ), esc_html( $label ) );

		$note = ( '' === $hint ) ? '' : sprintf( '<p class="bc-hint">%s</p>', esc_html( $hint ) );

		return '<div class="bc-row">' . $head . '<div class="bc-control">' . $control . $note . '</div></div>';
	}

	/**
	 * A segmented choice, for small option sets.
	 *
	 * @param string $name    Field name.
	 * @param array  $options Value => label.
	 * @param string $current Current value.
	 * @return string
	 */
	public static function segmented( $name, $options, $current ) {
		$out = '<div class="bc-seg" role="radiogroup">';

		foreach ( $options as $value => $label ) {
			$id   = 'bc_' . $name . '_' . sanitize_key( (string) $value );
			$out .= sprintf(
				'<input type="radio" id="%1$s" name="%2$s" value="%3$s"%4$s /><label for="%1$s">%5$s</label>',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( (string) $value ),
				checked( (string) $current, (string) $value, false ),
				esc_html( $label )
			);
		}

		return $out . '</div>';
	}

	/**
	 * A dropdown.
	 *
	 * @param string $name    Field name.
	 * @param array  $options Value => label.
	 * @param string $current Current value.
	 * @return string
	 */
	public static function select( $name, $options, $current ) {
		$out = sprintf( '<select class="bc-select" name="%1$s" id="bc_%1$s">', esc_attr( $name ) );

		foreach ( $options as $value => $label ) {
			$out .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( (string) $current, (string) $value, false ),
				esc_html( $label )
			);
		}

		return $out . '</select>';
	}

	/**
	 * A slider with a live read-out.
	 *
	 * @param string $name    Field name.
	 * @param int    $min     Minimum.
	 * @param int    $max     Maximum.
	 * @param mixed  $step    Step.
	 * @param mixed  $current Current value.
	 * @param string $unit    Suffix shown beside the value.
	 * @return string
	 */
	public static function slider( $name, $min, $max, $step, $current, $unit = '' ) {
		return sprintf(
			'<div class="bc-slider"><input type="range" id="bc_%1$s" name="%1$s" min="%2$s" max="%3$s" step="%4$s" value="%5$s" data-unit="%6$s" /><output class="bc-value" for="bc_%1$s">%5$s%6$s</output></div>',
			esc_attr( $name ),
			esc_attr( (string) $min ),
			esc_attr( (string) $max ),
			esc_attr( (string) $step ),
			esc_attr( (string) $current ),
			esc_attr( $unit )
		);
	}

	/**
	 * A toggle.
	 *
	 * @param string $name    Field name.
	 * @param bool   $current Whether it is on.
	 * @param string $label   Text beside the switch.
	 * @return string
	 */
	public static function toggle( $name, $current, $label ) {
		return sprintf(
			'<label class="bc-toggle"><input type="checkbox" name="%1$s" id="bc_%1$s" value="1"%2$s /><span class="bc-track" aria-hidden="true"></span><span class="bc-toggle-text">%3$s</span></label>',
			esc_attr( $name ),
			checked( (bool) $current, true, false ),
			esc_html( $label )
		);
	}

	/**
	 * A chip multi-select.
	 *
	 * @param string $name    Field name.
	 * @param array  $options Value => label.
	 * @param array  $chosen  Chosen values.
	 * @return string
	 */
	public static function chips( $name, $options, $chosen ) {
		$out = '<div class="bc-chips">';

		foreach ( $options as $value => $label ) {
			$id   = 'bc_' . $name . '_' . sanitize_key( (string) $value );
			$out .= sprintf(
				'<input type="checkbox" id="%1$s" name="%2$s[]" value="%3$s"%4$s /><label for="%1$s">%5$s</label>',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( (string) $value ),
				checked( in_array( (string) $value, $chosen, true ), true, false ),
				esc_html( $label )
			);
		}

		return $out . '</div>';
	}

	/**
	 * A single-line text field.
	 *
	 * @param string $name        Field name.
	 * @param string $current     Current value.
	 * @param string $placeholder Placeholder text.
	 * @return string
	 */
	public static function text( $name, $current, $placeholder = '' ) {
		return sprintf(
			'<input type="text" class="bc-text" name="%1$s" id="bc_%1$s" value="%2$s" placeholder="%3$s" autocomplete="off" />',
			esc_attr( $name ),
			esc_attr( (string) $current ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * A multi-line field.
	 *
	 * @param string $name        Field name.
	 * @param string $current     Current value.
	 * @param string $placeholder Placeholder text.
	 * @param int    $rows        Row count.
	 * @return string
	 */
	public static function area( $name, $current, $placeholder = '', $rows = 3 ) {
		return sprintf(
			'<textarea class="bc-area" name="%1$s" id="bc_%1$s" rows="%4$d" placeholder="%3$s">%2$s</textarea>',
			esc_attr( $name ),
			esc_textarea( (string) $current ),
			esc_attr( $placeholder ),
			(int) $rows
		);
	}
}
