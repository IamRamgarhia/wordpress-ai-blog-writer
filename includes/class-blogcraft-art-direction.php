<?php
/**
 * How generated images should look.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a post and a set of preferences into an image prompt.
 *
 * Two problems are being solved here, and they are different. The first is
 * subject: what should the picture actually show? Only the article knows that,
 * so the model that wrote the article writes the description. The second is
 * treatment: whether it is a photograph or a flat illustration, whether it is
 * bright or moody, what it must never contain. That is a standing preference,
 * not something to rediscover per post, so it lives on the blueprint.
 *
 * Handing a raw post title to an image model is why so many AI blog images look
 * like stock clip art of the title. A described scene with a stated treatment
 * is the difference.
 */
class Blogcraft_Art_Direction {

	/**
	 * Visual treatments offered.
	 *
	 * @return array
	 */
	public static function styles() {
		return array(
			'photo'        => __( 'Photograph', 'blogcraft' ),
			'editorial'    => __( 'Editorial photograph, magazine-like', 'blogcraft' ),
			'illustration' => __( 'Illustration', 'blogcraft' ),
			'flat'         => __( 'Flat vector', 'blogcraft' ),
			'isometric'    => __( 'Isometric', 'blogcraft' ),
			'threed'       => __( '3D render', 'blogcraft' ),
			'watercolour'  => __( 'Watercolour', 'blogcraft' ),
			'line'         => __( 'Line drawing', 'blogcraft' ),
			'minimal'      => __( 'Minimal, lots of space', 'blogcraft' ),
		);
	}

	/**
	 * The words each treatment contributes to a prompt.
	 *
	 * @return array
	 */
	private static function style_phrases() {
		return array(
			'photo'        => 'a photograph, natural lighting, realistic depth of field',
			'editorial'    => 'an editorial photograph as a magazine would run it, considered composition, natural light',
			'illustration' => 'a hand-drawn illustration with visible linework',
			'flat'         => 'a flat vector illustration, simple shapes, no gradients',
			'isometric'    => 'an isometric illustration, clean geometry, subtle shadows',
			'threed'       => 'a soft 3D render, matte materials, gentle studio lighting',
			'watercolour'  => 'a watercolour painting with soft edges and visible paper texture',
			'line'         => 'a single-weight line drawing on a plain background',
			'minimal'      => 'a minimal composition with a single clear subject and generous empty space',
		);
	}

	/**
	 * Moods offered.
	 *
	 * @return array
	 */
	public static function moods() {
		return array(
			''         => __( 'No preference', 'blogcraft' ),
			'bright'   => __( 'Bright and open', 'blogcraft' ),
			'warm'     => __( 'Warm', 'blogcraft' ),
			'cool'     => __( 'Cool and calm', 'blogcraft' ),
			'moody'    => __( 'Moody, low key', 'blogcraft' ),
			'contrast' => __( 'High contrast', 'blogcraft' ),
			'muted'    => __( 'Muted and understated', 'blogcraft' ),
		);
	}

	/**
	 * The words each mood contributes.
	 *
	 * @return array
	 */
	private static function mood_phrases() {
		return array(
			'bright'   => 'bright, airy, high key',
			'warm'     => 'warm tones, soft golden light',
			'cool'     => 'cool tones, calm and even light',
			'moody'    => 'low key, deep shadows, restrained',
			'contrast' => 'strong contrast, decisive light and shade',
			'muted'    => 'muted, desaturated, understated',
		);
	}

	/**
	 * What the picture should centre on.
	 *
	 * @return array
	 */
	public static function subjects() {
		return array(
			'object'   => __( 'The thing itself', 'blogcraft' ),
			'inuse'    => __( 'Someone using it', 'blogcraft' ),
			'scene'    => __( 'The setting it belongs in', 'blogcraft' ),
			'abstract' => __( 'An abstract representation', 'blogcraft' ),
			'detail'   => __( 'A close detail', 'blogcraft' ),
		);
	}

	/**
	 * Shapes offered, with the ratio each maps to.
	 *
	 * @return array
	 */
	public static function shapes() {
		return array(
			'16:9' => __( 'Wide, 16:9', 'blogcraft' ),
			'3:2'  => __( 'Classic, 3:2', 'blogcraft' ),
			'4:3'  => __( 'Standard, 4:3', 'blogcraft' ),
			'1:1'  => __( 'Square', 'blogcraft' ),
		);
	}

	/**
	 * Pixel dimensions for a shape.
	 *
	 * @param string $shape Ratio key.
	 * @return array Width and height.
	 */
	public static function dimensions( $shape ) {
		$map = array(
			'16:9' => array( 1344, 768 ),
			'3:2'  => array( 1216, 832 ),
			'4:3'  => array( 1152, 896 ),
			'1:1'  => array( 1024, 1024 ),
		);

		$shape = (string) $shape;

		return isset( $map[ $shape ] ) ? $map[ $shape ] : $map['16:9'];
	}

	/**
	 * The treatment half of a prompt, built from the blueprint alone.
	 *
	 * @param array $blueprint Blueprint.
	 * @return string
	 */
	public static function treatment( $blueprint ) {
		$parts = array();

		$styles = self::style_phrases();
		$style  = isset( $blueprint['image_style'] ) ? (string) $blueprint['image_style'] : 'photo';

		if ( isset( $styles[ $style ] ) ) {
			$parts[] = $styles[ $style ];
		}

		$moods = self::mood_phrases();
		$mood  = isset( $blueprint['image_mood'] ) ? (string) $blueprint['image_mood'] : '';

		if ( isset( $moods[ $mood ] ) ) {
			$parts[] = $moods[ $mood ];
		}

		$palette = isset( $blueprint['image_palette'] ) ? trim( (string) $blueprint['image_palette'] ) : '';

		if ( '' !== $palette ) {
			$parts[] = 'colour palette: ' . $palette;
		}

		$extra = isset( $blueprint['image_extra'] ) ? trim( (string) $blueprint['image_extra'] ) : '';

		if ( '' !== $extra ) {
			$parts[] = $extra;
		}

		return implode( ', ', $parts );
	}

	/**
	 * What the image must not contain.
	 *
	 * Text is excluded by default because image models render lettering as
	 * convincing gibberish, and a thumbnail with misspelt words on it looks
	 * worse than one with none.
	 *
	 * @param array $blueprint Blueprint.
	 * @return string
	 */
	public static function avoid( $blueprint ) {
		$parts = array();

		if ( ! isset( $blueprint['image_allow_text'] ) || ! $blueprint['image_allow_text'] ) {
			$parts[] = 'no text, no words, no lettering, no logos, no watermarks';
		}

		$negative = isset( $blueprint['image_avoid'] ) ? trim( (string) $blueprint['image_avoid'] ) : '';

		if ( '' !== $negative ) {
			$parts[] = $negative;
		}

		return implode( ', ', $parts );
	}

	/**
	 * Assemble a full prompt from a described subject and the blueprint.
	 *
	 * @param string $subject   What the picture shows.
	 * @param array  $blueprint Blueprint.
	 * @return string
	 */
	public static function assemble( $subject, $blueprint ) {
		$parts   = array();
		$subject = trim( (string) $subject );

		if ( '' !== $subject ) {
			$parts[] = $subject;
		}

		$treatment = self::treatment( $blueprint );

		if ( '' !== $treatment ) {
			$parts[] = $treatment;
		}

		$avoid = self::avoid( $blueprint );

		if ( '' !== $avoid ) {
			$parts[] = $avoid;
		}

		return implode( '. ', $parts ) . '.';
	}

	/**
	 * Ask the writing model to describe a picture for this article.
	 *
	 * Only the subject is asked for. Treatment is a standing preference and
	 * appending it here rather than asking for it keeps the model from
	 * quietly overriding a choice the user already made.
	 *
	 * @param string $title     Post title.
	 * @param string $topic     Original topic.
	 * @param array  $blueprint Blueprint.
	 * @param string $section   Section heading, when illustrating one.
	 * @return string A described subject, or '' when the model gives nothing usable.
	 */
	public static function describe( $title, $topic, $blueprint, $section = '' ) {
		$focus   = isset( $blueprint['image_subject'] ) ? (string) $blueprint['image_subject'] : 'object';
		$focuses = array(
			'object'   => 'the object or thing the article is about',
			'inuse'    => 'a person using or doing the thing the article is about',
			'scene'    => 'the place or setting the article belongs in',
			'abstract' => 'an abstract visual metaphor for the idea',
			'detail'   => 'a close detail of the thing the article is about',
		);

		$want = isset( $focuses[ $focus ] ) ? $focuses[ $focus ] : $focuses['object'];

		$user = 'Describe one photograph or illustration to accompany this article.' . "\n\n"
			. 'Article title: ' . $title . "\n"
			. ( '' === $topic ? '' : 'Subject: ' . $topic . "\n" )
			. ( '' === $section ? '' : 'This picture illustrates the section: ' . $section . "\n" )
			. "\n"
			. 'It should show ' . $want . ".\n\n"
			. "Reply with JSON of exactly this shape:\n"
			. '{"subject":""}' . "\n\n"
			. "Rules:\n"
			. "- One or two sentences describing what is in frame, concretely.\n"
			. "- Name real, specific things. No adjectives about mood or style.\n"
			. "- Do not mention cameras, lenses, art styles, or lighting.\n"
			. '- Do not include any text, words or signage in the scene.';

		try {
			$result = Blogcraft_Pipeline::ask_model(
				array(
					array(
						'role'    => 'system',
						'content' => 'You describe pictures for articles. You always reply with valid JSON and nothing else.',
					),
					array(
						'role'    => 'user',
						'content' => $user,
					),
				)
			);
		} catch ( Throwable $e ) {
			// An image is worth less than the post, so a failure here falls
			// back to the title rather than stopping anything.
			return '';
		}

		return isset( $result['subject'] ) ? trim( (string) $result['subject'] ) : '';
	}

	/**
	 * The prompt for a post, written by the model when that is switched on.
	 *
	 * @param string $title     Post title.
	 * @param string $topic     Original topic.
	 * @param array  $blueprint Blueprint.
	 * @param string $section   Section heading, when illustrating one.
	 * @return string
	 */
	public static function prompt_for( $title, $topic, $blueprint, $section = '' ) {
		$subject = '';

		if ( ! isset( $blueprint['image_describe'] ) || $blueprint['image_describe'] ) {
			$subject = self::describe( $title, $topic, $blueprint, $section );
		}

		if ( '' === $subject ) {
			$subject = ( '' === $section ) ? $title : $section;
		}

		return self::assemble( $subject, $blueprint );
	}
}
