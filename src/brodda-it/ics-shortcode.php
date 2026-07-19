<?php

defined('ABSPATH') or die();

final class BroddaITICSShortcode
{
    public static function init(): void
    {
        add_shortcode('ics_events', [self::class, 'render']);
    }

    public static function render($atts): string
    {

        $atts = shortcode_atts(array(
                'url' => '',
                'limit' => 50,
        ), $atts);

        if (empty($atts['url'])) {
            return '<p>No ICS URL provided.</p>';
        }

        // Cache key
        $cache_key = 'broddait_ics_cache_' . md5($atts['url']);

        // Try cache
        $body = get_transient($cache_key);

        // Cache miss
        if ($body === false) {

            $response = wp_remote_get($atts['url'], array(
                    'timeout' => 20,
                    'user-agent' => 'WordPress ICS Reader'
            ));

            if (is_wp_error($response)) {
                return '<p>Fetch failed: ' . esc_html($response->get_error_message()) . '</p>';
            }

            $body = wp_remote_retrieve_body($response);

            if (empty($body)) {
                return '<p>ICS response empty.</p>';
            }

            // Cache for 1 hour
            set_transient($cache_key, $body, HOUR_IN_SECONDS);
        }

        if (strpos($body, 'BEGIN:VCALENDAR') === false) {

            return '<pre style="white-space:pre-wrap;">'
                    . esc_html(substr($body, 0, 1000))
                    . '</pre>';
        }

        try {

            $ical = new ICal\ICal(false, array(
                    'defaultSpan' => 2,
                    'defaultTimeZone' => 'Europe/Berlin',
            ));

            $ical->initString($body);

            $events = $ical->events();

            if (empty($events)) {
                return '<p>No parsed events found.</p>';
            }

            ob_start();

            echo '
					<style>
					    .ics-month-headline {
					        font-size: 32px;
					        font-weight: 700;
					        margin: 50px 0 30px;
					        text-align: center;
					    }
					
					    .ics-event {
					        display: flex;
					        justify-content: center;
					        gap: 40px;
					        margin-bottom: 35px;
					        align-items: flex-start;
					        text-align: center;
					    }
					
					    .ics-date {
					        width: 260px;
					        flex-shrink: 0;
					        font-weight: 700;
					        text-align: right;
					    }
					
					    .ics-content {
					        width: 500px;
					        text-align: left;
					    }
					
					    .ics-title {
					        font-weight: 700;
					        margin-bottom: 8px;
					    }
					
					    .ics-description {
					        line-height: 1.5;
					        opacity: 0.85;
					    }
					
					    @media(max-width: 700px) {
					
					        .ics-event {
					            flex-direction: column;
					            gap: 10px;
					            align-items: center;
					        }
					
					        .ics-date {
					            width: auto;
					            text-align: center;
					        }
					
					        .ics-content {
					            width: 100%;
					            text-align: center;
					        }
					    }
					</style>
					';

            $count = 0;
            $current_month = '';

            foreach ($events as $event) {

                if ($count >= intval($atts['limit'])) {
                    break;
                }

                if (empty($event->dtstart)) {
                    continue;
                }

                $date_output = '';
                $month_headline = '';

                // Full-day event
                if (strlen($event->dtstart) === 8) {

                    $dt = DateTime::createFromFormat(
                            'Ymd',
                            $event->dtstart
                    );

                    if (!$dt) {
                        continue;
                    }

                    $date_output = wp_date(
                            'D d.m.Y',
                            $dt->getTimestamp()
                    );

                    $month_headline = wp_date(
                            'F Y',
                            $dt->getTimestamp()
                    );

                } else {

                    // Timed event
                    $dt = new DateTime($event->dtstart);

                    $date_output = wp_date(
                            'D d.m.Y, H:i',
                            $dt->getTimestamp()
                    );

                    $month_headline = wp_date(
                            'F Y',
                            $dt->getTimestamp()
                    );
                }

                // Month headline
                if ($month_headline !== $current_month) {

                    $current_month = $month_headline;

                    echo '<h2 class="ics-month-headline">'
                            . esc_html($month_headline)
                            . '</h2>';
                }

                $title = !empty($event->summary)
                        ? esc_html($event->summary)
                        : '';

                $description = !empty($event->description)
                        ? nl2br(esc_html($event->description))
                        : '';

                echo '<div class="ics-event">';

                echo '<div class="ics-date">';
                echo esc_html($date_output);
                echo '</div>';

                echo '<div class="ics-content">';

                echo '<div class="ics-title">';
                echo $title;
                echo '</div>';

                echo '<div class="ics-description">';
                echo $description;
                echo '</div>';

                echo '</div>';

                echo '</div>';

                $count++;
            }

            return ob_get_clean();

        } catch (Exception $e) {

            return '<pre>'
                    . esc_html($e->getMessage())
                    . '</pre>';
        }
    }
}

BroddaITICSShortcode::init();
