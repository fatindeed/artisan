<?php

namespace App\Console\Commands;

use RuntimeException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use App\Club;
use App\Nation;
use App\Player;

class Pesdb extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pesdb:players {--debug}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get PES 2019 Players';

    /**
     * @var bool
     */
    private $pcntl_loaded;

    /**
     * @var \Symfony\Component\Console\Helper\ProgressBar
     */
    private $bar;

    /**
     * @var \GuzzleHttp\Client
     */
    private $http_client;

    /**
     * @var int
     */
    private $last_page;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->init();
        $this->bar->start();
        do {
            $page = Cache::get('page');
            $this->bar->setMessage($page, 'page');
            $this->bar->setProgress(0);
            $players = $this->getPlayerList($page);
            $this->bar->setMaxSteps(count($players));
            foreach($players as $player_id => $player_name) {
                $this->getPlayerDetail((int) $player_id, $player_name);
                $this->bar->advance();
            }
            Cache::increment('page');
        }
        while($page < $this->last_page);
        $this->bar->finish();
    }

    private function init(): void
    {
        $this->pcntl_loaded = extension_loaded('pcntl');
        if($this->pcntl_loaded) {
            pcntl_signal(\SIGINT, [$this, 'terminate']);
        }
        else {
            $this->error('pcntl extension is not loaded.');
        }
        $this->bar = $this->output->createProgressBar(32);
        $this->bar->setFormat('Page %page%: %message%'.PHP_EOL.' %current%/%max% [%bar%] %percent:3s%%');
        $this->http_client = new Client([
            'base_uri' => 'http://pesdb.net',
            'connect_timeout' => 5,
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/69.0.3497.92 Safari/537.36',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
            ],
            'debug' => $this->option('debug') ?: false
        ]);
        Cache::rememberForever('page', function () {
            return 1;
        });
    }

    public function terminate() {
        $this->info(PHP_EOL.'Ctrl-C pressed, exiting now...');
        exit();
    }

    public function signalDispatch(string $uri) {
        if($this->pcntl_loaded) {
            pcntl_signal_dispatch();
        }
        $this->bar->display();
        sleep(5);
    }

    private function load(string $uri, array $options = []): string
    {
        try {
            $this->signalDispatch($uri);
            $response = $this->http_client->get($uri, $options);
            return $response->getBody();
        } catch(ConnectException $e) {
            $context = $e->getHandlerContext();
            // Connection timed out / Operation timed out
            if($context['errno'] == 28) {
                // $this->error($uri.': '.$context['error']);
            }
            // Connection refused
            else if($context['errno'] == 7) {
                $this->error($uri.': '.$context['error']);
                sleep(60);
            }
            else {
                $this->error($uri.': '.$context['error']);
                exit;
            }
        } catch(ClientException $e) {
            $this->error($uri.': '.$e->getMessage());
            sleep(300);
        }
        return $this->load($uri, $options);
    }

    private function getPlayerList(int $page): array
    {
        $this->bar->setMessage('Loading player list');
        $html = $this->load('/pes2019/?page='.$page);
        if(!preg_match('|<div class="pages">.*<a href="\./\?page=\d+".*>(\d+)</a>\..*</div>|isU', $html, $match_page)) {
            throw new RuntimeException('page no not found.', 1);
        }
        $this->last_page = intval($match_page[1]);
        if(!preg_match('|<table class="players">(.*)</table>|isU', $html, $match_table)) {
            throw new RuntimeException('players table not found.', 1);
        }
        if(!preg_match_all('|<tr>.*<a href="\./\?id=(\d+)">(.+)</a>.*</tr>|isU', $match_table[1], $match_rows)) {
            throw new RuntimeException('players rows not found.', 1);
        }
        return array_combine($match_rows[1], $match_rows[2]);
    }

    private function getPlayerDetail(int $player_id, string $player_name): void
    {
        $player = Player::findOrNew($player_id);
        if ($player->exists) {
            return;
        }
        $player->id = $player_id;
        $this->bar->setMessage('Loading player - '.$player_name);
        $html = $this->load('/pes2019/?id='.$player_id);
        if(!preg_match('|<table id="table_0" class="player" style="display: table; clear: both;">(.*)</table>|isU', $html, $info_table)) {
            throw new RuntimeException('player info table not found.', 1);
        }
        if(!preg_match_all('|</th><td.*>(.*)</td></tr>|isU', $info_table[1], $match_data)) {
            throw new RuntimeException('player properties not found.', 1);
        }
        $data = array_map('strip_tags', array_map('html_entity_decode', $match_data[1]));
        if ($offset = array_search('Free Agents', $data)) {
            array_splice($data, $offset, 0, [NULL]);
        } else {
            $club = Club::firstOrNew(['name' => $data[2]]);
            if (!$club->exists) {
                $club->league = $data[3];
                $club->save();
            }
        }
        $nation = Nation::firstOrNew(['name' => $data[4]]);
        if (!$nation->exists) {
            $nation->region = $data[5];
            $nation->save();
        }
        $player->name = $data[0];
        $player->club_team = $data[2];
        $player->club_number = $data[1];
        $player->nationality = $data[4];
        $player->height = $data[6];
        $player->weight = $data[7];
        $player->age = $data[8];
        $player->foot = $data[9];
        $player->position = $data[11];
        if(!preg_match_all('|<span class="pos(\d)" title=".*">([A-Z]+)</span>|isU', $info_table[1], $match_positions, PREG_SET_ORDER)) {
            throw new RuntimeException('positions not found.', 1);
        }
        $positions_all = [];
        foreach ($match_positions as $match_position) {
            $positions_all[$match_position[2]] = intval($match_position[1]);
        }
        $player->positions_all = $positions_all;
        if(!preg_match('|var max_level = (\d+);|isU', $html, $match_max_level)) {
            throw new RuntimeException('max_level not found.', 1);
        }
        $player->max_level = $match_max_level[1];
        if(!preg_match('|<script>abilities =(.*);</script>|isU', $html, $match_abilities)) {
            throw new RuntimeException('abilities not found.', 1);
        }
		$abilities = json_decode(trim($match_abilities[1]));
        $player->overall_rating = $abilities[23][29];
        $player->overall_at_max_level = $abilities[23][$player->max_level - 1];
        $abilities_lv30 = [];
        foreach ($abilities as $key => $ability) {
            $abilities_lv30[$key] = $ability[29];
        }
        $player->abilities = $abilities_lv30;
        $player->abilities_all = $abilities;
        if(!preg_match('|<table class="playing_styles">(.*)</table>|isU', $html, $match_playing_styles_table)) {
            throw new RuntimeException('playing style not found.', 1);
        }
        if(!preg_match_all('|<tr><td>(.*)</td></tr>|isU', $match_playing_styles_table[1], $match_playing_styles)) {
            throw new RuntimeException('players style rows not found.', 1);
        }
        $player->playing_styles = $match_playing_styles[1];
        $player->save();
        // Featured Players
        // <td style="vertical-align: bottom;">
        //   <div style="text-align: center;"><a href="./?all=1&amp;featured=69">ARSENAL Club Selection</a></div>
        //   <img src="images/players/40323.png" class="player_image" alt="" />
        // </td>
    }
}
