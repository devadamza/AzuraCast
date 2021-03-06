<?php
namespace App\Controller\Stations\Reports;

use Azura\Cache;
use Doctrine\ORM\EntityManager;
use App\Entity;
use App\Http\Request;
use App\Http\Response;
use InfluxDB\Database;
use Psr\Http\Message\ResponseInterface;

class OverviewController
{
    /** @var EntityManager */
    protected $em;

    /** @var Database */
    protected $influx;

    /**
     * @param EntityManager $em
     * @param Database $influx
     * @see \App\Provider\StationsProvider
     */
    public function __construct(EntityManager $em, Database $influx)
    {
        $this->em = $em;
        $this->influx = $influx;
    }

    public function __invoke(Request $request, Response $response, $station_id): ResponseInterface
    {
        $station = $request->getStation();

        // Get current analytics level.

        /** @var Entity\Repository\SettingsRepository $settings_repo */
        $settings_repo = $this->em->getRepository(Entity\Settings::class);

        $analytics_level = $settings_repo->getSetting(Entity\Settings::LISTENER_ANALYTICS, Entity\Analytics::LEVEL_ALL);

        if ($analytics_level === Entity\Analytics::LEVEL_NONE) {
            // The entirety of the dashboard can't be shown, so redirect user to the profile page.

            return $request->getView()->renderToResponse($response, 'stations/reports/restricted');
        }

        /* Statistics */
        $threshold = strtotime('-1 month');

        // Statistics by day.
        $resultset = $this->influx->query('SELECT * FROM "1d"."station.' . $station->getId() . '.listeners" WHERE time > now() - 30d',
            [
                'epoch' => 'ms',
            ]);

        $daily_stats = $resultset->getPoints();

        $daily_ranges = [];
        $daily_averages = [];
        $days_of_week = [];

        foreach ($daily_stats as $stat) {
            // Add 12 hours to statistics so they always land inside the day they represent.
            $stat['time'] = $stat['time'] + (60 * 60 * 12 * 1000);

            $daily_ranges[] = [$stat['time'], $stat['min'], $stat['max']];
            $daily_averages[] = [$stat['time'], round($stat['value'], 2)];

            $day_of_week = date('l', round($stat['time'] / 1000));
            $days_of_week[$day_of_week][] = $stat['value'];
        }

        $day_of_week_stats = [];
        foreach ($days_of_week as $day_name => $day_totals) {
            $day_of_week_stats[] = [$day_name, round(array_sum($day_totals) / count($day_totals), 2)];
        }

        // Statistics by hour.
        $resultset = $this->influx->query('SELECT * FROM "1h"."station.' . $station->getId() . '.listeners"', [
            'epoch' => 'ms',
        ]);

        $hourly_stats = $resultset->getPoints();

        $hourly_averages = [];
        $hourly_ranges = [];
        $totals_by_hour = [];

        foreach ($hourly_stats as $stat) {
            $hourly_ranges[] = [$stat['time'], $stat['min'], $stat['max']];
            $hourly_averages[] = [$stat['time'], round($stat['value'], 2)];

            $hour = (int)date('G', round($stat['time'] / 1000));
            $totals_by_hour[$hour][] = $stat['value'];
        }

        $averages_by_hour = [];
        for ($i = 0; $i < 24; $i++) {
            $totals = $totals_by_hour[$i] ?: [0];
            $averages_by_hour[] = [$i . ':00', round(array_sum($totals) / count($totals), 2)];
        }

        /* Play Count Statistics */

        $song_totals_raw = [];
        $song_totals_raw['played'] = $this->em->createQuery(/** @lang DQL */'SELECT 
            sh.song_id, COUNT(sh.id) AS records
            FROM App\Entity\SongHistory sh
            WHERE sh.station_id = :station_id AND sh.timestamp_start >= :timestamp
            GROUP BY sh.song_id
            ORDER BY records DESC')
            ->setParameter('station_id', $station->getId())
            ->setParameter('timestamp', $threshold)
            ->setMaxResults(40)
            ->getArrayResult();

        // Compile the above data.
        $song_totals = [];

        /** @var Entity\Repository\SongRepository $song_repo */
        $song_repo = $this->em->getRepository(Entity\Song::class);

        $get_song_q = $this->em->createQuery(/** @lang DQL */'SELECT s 
            FROM App\Entity\Song s
            WHERE s.id = :song_id');

        foreach ($song_totals_raw as $total_type => $total_records) {
            foreach ($total_records as $total_record) {
                $song = $get_song_q->setParameter('song_id', $total_record['song_id'])
                    ->getArrayResult();

                $total_record['song'] = $song[0];

                $song_totals[$total_type][] = $total_record;
            }

            $song_totals[$total_type] = array_slice((array)$song_totals[$total_type], 0, 10, true);
        }

        /* Song "Deltas" (Changes in Listener Count) */
        $threshold = strtotime('-2 weeks');

        // Get all songs played in timeline.
        $songs_played_raw = $this->em->createQuery(/** @lang DQL */'SELECT sh, s
            FROM App\Entity\SongHistory sh
            LEFT JOIN sh.song s
            WHERE sh.station_id = :station_id 
            AND sh.timestamp_start >= :timestamp 
            AND sh.listeners_start IS NOT NULL
            ORDER BY sh.timestamp_start ASC')
            ->setParameter('station_id', $station->getId())
            ->setParameter('timestamp', $threshold)
            ->getArrayResult();

        $songs_played_raw = array_values($songs_played_raw);
        $songs = [];

        foreach ($songs_played_raw as $i => $song_row) {
            // Song has no recorded ending.
            if ($song_row['timestamp_end'] == 0) {
                continue;
            }

            $song_row['stat_start'] = $song_row['listeners_start'];
            $song_row['stat_end'] = $song_row['listeners_end'];
            $song_row['stat_delta'] = $song_row['delta_total'];

            $songs[] = $song_row;
        }

        usort($songs, function ($a_arr, $b_arr) {
            $a = $a_arr['stat_delta'];
            $b = $b_arr['stat_delta'];

            if ($a == $b) {
                return 0;
            }

            return ($a > $b) ? 1 : -1;
        });

        return $request->getView()->renderToResponse($response, 'stations/reports/overview', [
            'day_of_week_stats' => json_encode((array)$day_of_week_stats),
            'daily_ranges' => json_encode((array)$daily_ranges),
            'daily_averages' => json_encode((array)$daily_averages),
            'hourly_ranges' => json_encode((array)$hourly_ranges),
            'hourly_averages' => json_encode((array)$hourly_averages),
            'averages_by_hour' => json_encode((array)$averages_by_hour),
            'song_totals' => $song_totals,
            'best_performing_songs' => \array_reverse(\array_slice($songs, -5)),
            'worst_performing_songs' => \array_slice($songs, 0, 5),
        ]);
    }
}
