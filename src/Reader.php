<?php

namespace EST;

use DateInterval;
use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Query;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Symfony\Component\DomCrawler\Crawler;

class Reader
{
    private const BASE_HOST = 'https://www.e-st.lv';
    private const LOGIN_URL = self::BASE_HOST . '/lv/private/user-authentification/';
    private const DATA_URL  = self::BASE_HOST . '/lv/private/paterini-un-norekini/paterinu-grafiki/';

    public const PERIOD_DAY   = 'D';
    public const PERIOD_MONTH = 'M';
    public const PERIOD_YEAR  = 'Y';

    public const GRANULARITY_NATIVE = 'NATIVE';
    public const GRANULARITY_HOUR   = 'H';
    public const GRANULARITY_DAY    = 'D';

    private string $login;
    private string $password;
    private int    $meterId;
    private Client $httpClient;

    /**
     * @param string $login
     * @param string $password
     * @param string $meterId
     */
    public function __construct(string $login, string $password, string $meterId)
    {
        $this->login      = $login;
        $this->password   = $password;
        $this->meterId    = $meterId;
        $this->httpClient = new Client(
            [
                'headers'         => [
                    'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/97.0.4692.71 Safari/537.36',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Referer'      => self::BASE_HOST,
                ],
                'verify'          => true,
                'timeout'         => 30,
                'cookies'         => true,
                'allow_redirects' => [
                    'max' => 3,
                ],
            ],
        );
    }

    /**
     * @param array $options
     * @return string
     */
    private function getDataUrl(array $options): string
    {
        $date        = (new DateTime())->sub(new DateInterval('P1D'));
        $period      = $options['period'] ?? self::PERIOD_DAY;
        $year        = $options['year'] ?? $date->format('Y');
        $month       = $options['month'] ?? $date->format('m');
        $day         = $options['day'] ?? $date->format('d');
        $granularity = $options['granularity'] ?? self::GRANULARITY_HOUR;
        $params      = [
            'counterNumber' => $this->meterId,
            'period'        => $period,
        ];

        if ($period === self::PERIOD_YEAR) {
            $params['year'] = $year;
        }

        if ($period === self::PERIOD_MONTH) {
            $params['year']        = $year;
            $params['month']       = $month;
            $params['granularity'] = $granularity;
        }

        if ($period === self::PERIOD_DAY) {
            $params['date']        = "{$day}.{$month}.{$year}";
            $params['granularity'] = $granularity;
        }

        return self::DATA_URL . '?' . Query::build($params);
    }

    /**
     * @param array $data
     * @return array
     */
    private function formatResponse(array $data): array
    {
        $formatted = [];

        foreach (['A+' => 'consumed', 'A-' => 'returned'] as $key => $direction) {
            $formatted[$direction] = array_map(static function (array $item) {
                return [
                    'timestamp' => $item['timestamp'],
                    'value'     => $item['value'],
                ];
            }, $data['values'][$key]['total']['data'] ?? []);
        }

        return $formatted;
    }

    /**
     * @param int|null $year
     * @param int|null $month
     * @param int|null $day
     * @return array
     */
    public function getDayData(int $year = null, int $month = null, int $day = null): array
    {
        return $this->fetch(
            [
                'period'      => self::PERIOD_DAY,
                'month'       => $month,
                'year'        => $year,
                'day'         => $day,
                'granularity' => self::GRANULARITY_HOUR,
            ]
        );
    }

    /**
     * @param int|null $year
     * @param int|null $month
     * @param string $granularity
     * @return array
     */
    public function getMonthData(int $year = null, int $month = null, string $granularity = self::GRANULARITY_DAY)
    {
        return $this->fetch(
            [
                'period'      => self::PERIOD_MONTH,
                'month'       => $month,
                'year'        => $year,
                'granularity' => $granularity,
            ]
        );
    }

    /**
     * @param int|null $year
     * @return array
     */
    public function getYearData(int $year = null)
    {
        return $this->fetch(
            [
                'period' => self::PERIOD_YEAR,
                'year'   => $year,
            ]
        );
    }

    /**
     * @param array $options
     * @return array
     */
    public function getCustomData(array $options): array
    {
        return $this->fetch($options);
    }

    /**
     * @param array $options
     * @return array
     */
    private function fetch(array $options): array
    {
        try {
            $url      = $this->getDataUrl($options);
            $response = $this->httpClient->request('GET', $url);
            $content  = $response->getBody()->getContents();
            $crawler  = new Crawler($content);

            if ($crawler->filter('form.authenticate')->count() > 0) {
                $fields = ['_token', 'returnUrl'];
                $values = [];

                foreach ($fields as $field) {
                    $values[$field] = $crawler->filter("input[name=$field]")->attr('value');
                }

                $values['login']    = $this->login;
                $values['password'] = $this->password;

                $response = $this->httpClient->request('POST', self::LOGIN_URL, ['body' => Query::build($values)]);
                $content  = $response->getBody()->getContents();
                $crawler  = new Crawler($content);
            }

            $json    = $crawler->filter('div.chart')->attr('data-values');
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            throw new ReaderException(
                sprintf('Failed fetching data from the %s.', $url ?? 'remote'),
                $e,
            );
        } catch (LogicException|InvalidArgumentException $e) {
            throw new ReaderException('Failed extracting data from the response.', $e);
        } catch (JsonException $e) {
            throw new ReaderException('Failed decoding extracted data.', $e);
        }

        return $this->formatResponse($decoded);
    }
}
