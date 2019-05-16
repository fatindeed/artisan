<?php

namespace App\Console\Commands;

use RuntimeException;
use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use App\League;
use App\Team;

class PesdbLeagues extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pesdb:leagues {--debug}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get PES 2019 Leagues';

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
        $leagues = $this->getLeagues();
        $bar = $this->output->createProgressBar(count($leagues));
        $bar->start();
        foreach ($leagues as $data) {
            $bar->setMessage('Loading '.$data[2]);
            $bar->display();
            $id = basename($data[1]);
            $league = League::firstOrCreate(['id' => $id], [
                'name' => $data[2],
                'uri' => $data[1]
            ]);
            $this->getLeagueTeams($league);
            $bar->advance();
        }
        $bar->finish();
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
        $this->http_client = new Client([
            'base_uri' => 'https://www.pesmaster.com',
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
        // Cache::rememberForever('page', function () {
        //     return 1;
        // });
    }

    public function terminate() {
        $this->info(PHP_EOL.'Ctrl-C pressed, exiting now...');
        exit();
    }

    public function signalDispatch(string $uri) {
        if($this->pcntl_loaded) {
            pcntl_signal_dispatch();
        }
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

    private function getLeagues(): array
    {
        $html = $this->load('/pes-2019/');
        if(!preg_match('|<ul class="leagues-list">(.*)</ul>|isU', $html, $match_list)) {
            throw new RuntimeException('league list not found.', 1);
        }
        if(!preg_match_all('|<a href="(.+)"><li class="leagues-list-el">.+<div class="leagues-list-text">(.+)</div>|isU', $match_list[1], $match_items, PREG_SET_ORDER)) {
            throw new RuntimeException('league items not found.', 1);
        }
        return $match_items;
    }

    private function getLeagueTeams(League $league)
    {
        $html = $this->load($league->uri);
        if(!preg_match('|<table class="squad-table sortable" id="search-result-table">.*<tbody>(.*)</tbody>\s*</table>|isU', $html, $match_table)) {
            throw new RuntimeException('team table not found.', 1);
        }
        if(!preg_match_all('|<tr>(.+)</tr>|isU', $match_table[1], $match_rows)) {
            throw new RuntimeException('team rows not found.', 1);
        }
        foreach ($match_rows[1] as $row_html) {
            if(!preg_match('|<a class="namelink" href="(.+)">(.+)</a>|isU', $row_html, $match_name)) {
                throw new RuntimeException('team name not found.', 1);
            }
            if(!preg_match_all('|<span class=\'.* squad-table-stat\'>(\d+)</span>|isU', $row_html, $match_stat)) {
                throw new RuntimeException('team stat not found.', 1);
            }
            $id = basename($match_name[1]);
            Team::firstOrCreate(['id' => $id], [
                'league_id' => $league->id,
                'name' => $match_name[2],
                'uri' => $match_name[1],
                'ovr' => $match_stat[1][0],
                'def' => $match_stat[1][1],
                'mid' => $match_stat[1][2],
                'fwd' => $match_stat[1][3],
                'phy' => $match_stat[1][4],
                'spd' => $match_stat[1][5]
            ]);
        }
    }
}
