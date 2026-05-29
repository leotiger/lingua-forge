<?php
/**
 * Classic widget wrapper for the Language Switcher.
 *
 * Defined in the global namespace so WP's widget registry can instantiate it
 * by class name. Delegates entirely to Switcher::render_switcher() so the
 * output is identical to the block and the shortcode.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- lsflr_ is the registered feature prefix for the Language Switcher public API (CONTRIBUTING §3).

/**
 * Classic widget wrapper for the Language Switcher.
 *
 * Supports the same `direction`, `show`, `customLabel`, and `iconHtml`
 * attributes as the block and the shortcode via the widget form.
 */
class Lsflr_Switcher_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'lsflr_switcher',
			__( 'Language Switcher', 'lingua-forge' ),
			[ 'description' => __( 'Displays the Lingua Forge language switcher.', 'lingua-forge' ) ]
		);
	}

	/**
	 * Front-end output. Delegates to Switcher::render_switcher().
	 *
	 * @param array $args     Widget wrapper arguments from the theme.
	 * @param array $instance Saved widget settings.
	 */
	public function widget( $args, $instance ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$atts = [
			'direction'   => $instance['direction']   ?? 'down',
			'show'        => $instance['show']        ?? 'label',
			'customLabel' => $instance['customLabel'] ?? __( 'Language', 'lingua-forge' ),
		];

		// Use the Switcher already wired to the Router singleton rather than
		// creating a new instance — the constructor registers hooks, so a fresh
		// instantiation here would double-register wp_enqueue_scripts / init /
		// widgets_init actions on every widget render.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_switcher() escapes all output internally.
		echo \LinguaForge\Router\Router::get_instance()->switcher->render_switcher( $atts );
	}

	/**
	 * Widget settings form in the admin Widgets screen.
	 *
	 * @param array $instance Current widget settings.
	 */
	public function form( $instance ): void {
		$direction = $instance['direction'] ?? 'down';
		$show      = $instance['show']      ?? 'label';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show' ) ); ?>"><?php esc_html_e( 'Display', 'lingua-forge' ); ?></label>
			<select id="<?php echo esc_attr( $this->get_field_id( 'show' ) ); ?>"
			        name="<?php echo esc_attr( $this->get_field_name( 'show' ) ); ?>">
				<option value="label"      <?php selected( $show, 'label' ); ?>><?php esc_html_e( 'Language label', 'lingua-forge' ); ?></option>
				<option value="icon"       <?php selected( $show, 'icon' ); ?>><?php esc_html_e( 'Icon only', 'lingua-forge' ); ?></option>
				<option value="icon-label" <?php selected( $show, 'icon-label' ); ?>><?php esc_html_e( 'Icon + label', 'lingua-forge' ); ?></option>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'direction' ) ); ?>"><?php esc_html_e( 'Dropdown direction', 'lingua-forge' ); ?></label>
			<select id="<?php echo esc_attr( $this->get_field_id( 'direction' ) ); ?>"
			        name="<?php echo esc_attr( $this->get_field_name( 'direction' ) ); ?>">
				<option value="down" <?php selected( $direction, 'down' ); ?>><?php esc_html_e( 'Down', 'lingua-forge' ); ?></option>
				<option value="up"   <?php selected( $direction, 'up' ); ?>><?php esc_html_e( 'Up', 'lingua-forge' ); ?></option>
			</select>
		</p>
		<?php
	}

	/**
	 * Save widget settings.
	 *
	 * @param array $new_instance New settings from the form.
	 * @param array $old_instance Previous settings.
	 * @return array Sanitized settings to save.
	 */
	public function update( $new_instance, $old_instance ): array { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return [
			'direction'   => in_array( $new_instance['direction'] ?? '', [ 'down', 'up' ], true )
				? $new_instance['direction']
				: 'down',
			'show'        => in_array( $new_instance['show'] ?? '', [ 'label', 'icon', 'icon-label' ], true )
				? $new_instance['show']
				: 'label',
		];
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
