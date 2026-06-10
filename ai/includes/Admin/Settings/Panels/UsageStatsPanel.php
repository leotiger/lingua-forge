<?php
/**
 * Class LinguaForge\AI\Admin\Settings\Panels\UsageStatsPanel
 *
 * Renders the AI token-usage table on the AI Usage & Cache tab.
 * Reads from UsageRecorder and presents a date-filtered summary
 * grouped by feature, provider, and model.
 *
 * @package LinguaForge\AI\Admin\Settings\Panels
 * @since   2.1.9
 */

namespace LinguaForge\AI\Admin\Settings\Panels;

use LinguaForge\AI\Admin\SettingsPage;
use LinguaForge\AI\Core\UsageRecorder;

defined( 'ABSPATH' ) || exit;

class UsageStatsPanel {

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Date-range choices for the usage table.
     *
     * Maps the GET-param value to a human label and a "since" date (UTC).
     * All-time is represented by an empty since string.
     *
     * @return array<string, array{label:string, since:string}>
     */
    private static function ranges(): array {
        return [
            'today' => [
                'label' => __( 'Today', 'lingua-forge' ),
                'since' => gmdate( 'Y-m-d' ),
            ],
            '7' => [
                'label' => __( 'Last 7 days', 'lingua-forge' ),
                'since' => gmdate( 'Y-m-d', strtotime( '-6 days' ) ),
            ],
            '30' => [
                'label' => __( 'Last 30 days', 'lingua-forge' ),
                'since' => gmdate( 'Y-m-d', strtotime( '-29 days' ) ),
            ],
            'all' => [
                'label' => __( 'All time', 'lingua-forge' ),
                'since' => '',
            ],
        ];
    }

    // =========================================================================
    // Render
    // =========================================================================

    /**
     * Output the AI usage section — date-range buttons + token-consumption table.
     *
     * Read-only; no form submission. The date range is driven by the `range`
     * GET parameter so each button is a plain link (bookmarkable). Default: 30 days.
     */
    public static function render(): void {

        $ranges = self::ranges();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET param; no data modified.
        $active = sanitize_key( wp_unslash( $_GET['range'] ?? '30' ) );
        if ( ! array_key_exists( $active, $ranges ) ) {
            $active = '30';
        }

        $criteria = [];
        if ( $ranges[ $active ]['since'] !== '' ) {
            $criteria['since'] = $ranges[ $active ]['since'];
        }

        $rows         = UsageRecorder::query( $criteria );
        $total_inputs = array_sum( array_column( $rows, 'input_tokens' ) );
        $total_outpts = array_sum( array_column( $rows, 'output_tokens' ) );
        $total_total  = array_sum( array_column( $rows, 'total_tokens' ) );
        $total_reqs   = array_sum( array_column( $rows, 'request_count' ) );

        $base_url = admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG );

        ?>
        <h2><?php esc_html_e( 'AI Usage', 'lingua-forge' ); ?></h2>

        <p>
            <?php
            esc_html_e(
                'Token consumption rolled up by feature, provider, and model. Recorded on every successful AI call; Test Connection pings are excluded so they don\'t skew the totals.',
                'lingua-forge'
            );
            ?>
        </p>

        <p class="description">
            <?php esc_html_e( 'Check your account, billing, or remaining credits directly on the provider console:', 'lingua-forge' ); ?>
            <?php
            $lf_consoles = [
                'anthropic' => [ 'label' => __( 'Anthropic Console', 'lingua-forge' ), 'url' => 'https://console.anthropic.com/' ],
                'openai'    => [ 'label' => __( 'OpenAI Platform',    'lingua-forge' ), 'url' => 'https://platform.openai.com/'   ],
                'gemini'    => [ 'label' => __( 'Google AI Studio',   'lingua-forge' ), 'url' => 'https://aistudio.google.com/'   ],
            ];
            $lf_links = [];
            foreach ( $lf_consoles as $lf_console ) {
                $lf_links[] = sprintf(
                    '<a href="%s" target="_blank" rel="noopener noreferrer">%s ↗</a>',
                    esc_url( $lf_console['url'] ),
                    esc_html( $lf_console['label'] )
                );
            }
            echo implode( ' &middot; ', $lf_links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link built with esc_url/esc_html above.
            ?>
        </p>

        <p class="lingua-forge-range-buttons">
            <?php foreach ( $ranges as $range_key => $range ) :
                $href      = add_query_arg( 'range', $range_key, $base_url ) . '#ai-usage';
                $is_active = ( $range_key === $active );
                ?>
                <a
                    href="<?php echo esc_url( $href ); ?>"
                    class="button <?php echo $is_active ? 'button-primary' : 'button-secondary'; ?>"
                ><?php echo esc_html( $range['label'] ); ?></a>
            <?php endforeach; ?>
        </p>

        <?php if ( UsageRecorder::row_count() === 0 ) : ?>

            <div class="lingua-forge-settings-note">
                <p><?php esc_html_e( 'No AI usage recorded yet. Once an editor runs a translation, generates a meta description, or uses any other AI feature, totals will appear here.', 'lingua-forge' ); ?></p>
            </div>

        <?php elseif ( empty( $rows ) ) : ?>

            <div class="lingua-forge-settings-note">
                <p><?php echo esc_html( sprintf(
                    /* translators: %s is a date-range label like "Today" or "Last 7 days". */
                    __( 'No AI usage recorded in the selected window (%s). Pick a wider range above.', 'lingua-forge' ),
                    $ranges[ $active ]['label']
                ) ); ?></p>
            </div>

        <?php else : ?>

            <table class="widefat striped lingua-forge-usage-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Feature',       'lingua-forge' ); ?></th>
                        <th><?php esc_html_e( 'Provider',      'lingua-forge' ); ?></th>
                        <th><?php esc_html_e( 'Model',         'lingua-forge' ); ?></th>
                        <th class="lingua-forge-num"><?php esc_html_e( 'Requests',      'lingua-forge' ); ?></th>
                        <th class="lingua-forge-num"><?php esc_html_e( 'Input tokens',  'lingua-forge' ); ?></th>
                        <th class="lingua-forge-num"><?php esc_html_e( 'Output tokens', 'lingua-forge' ); ?></th>
                        <th class="lingua-forge-num"><?php esc_html_e( 'Total tokens',  'lingua-forge' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $row['feature_key'] ); ?></code></td>
                            <td><?php echo esc_html( $row['provider'] ); ?></td>
                            <td><code><?php echo esc_html( $row['model'] ); ?></code></td>
                            <td class="lingua-forge-num"><?php echo esc_html( number_format_i18n( $row['request_count'] ) ); ?></td>
                            <td class="lingua-forge-num"><?php echo esc_html( number_format_i18n( $row['input_tokens']  ) ); ?></td>
                            <td class="lingua-forge-num"><?php echo esc_html( number_format_i18n( $row['output_tokens'] ) ); ?></td>
                            <td class="lingua-forge-num"><strong><?php echo esc_html( number_format_i18n( $row['total_tokens'] ) ); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3"><?php esc_html_e( 'Total', 'lingua-forge' ); ?></th>
                        <th class="lingua-forge-num"><?php echo esc_html( number_format_i18n( $total_reqs   ) ); ?></th>
                        <th class="lingua-forge-num"><?php echo esc_html( number_format_i18n( $total_inputs ) ); ?></th>
                        <th class="lingua-forge-num"><?php echo esc_html( number_format_i18n( $total_outpts ) ); ?></th>
                        <th class="lingua-forge-num"><strong><?php echo esc_html( number_format_i18n( $total_total ) ); ?></strong></th>
                    </tr>
                </tfoot>
            </table>

        <?php endif; ?>
        <?php
    }
}
