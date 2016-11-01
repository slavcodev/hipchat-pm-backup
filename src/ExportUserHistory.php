<?php
/**
 * Slavcodev Components
 *
 * @author Veaceslav Medvedev <slavcopost@gmail.com>
 * @license http://www.opensource.org/licenses/bsd-license.php
 */

namespace Acme\Hipchat;

use DomainException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Tool to export 1-1 chat history.
 *
 * Usage:
 *
 * ~~~bash
 * hipchat hipchat:export <token> <user> [<user>]
 * ~~~
 *
 * @see https://developer.atlassian.com/hipchat/guide/hipchat-rest-api/api-access-tokens
 * @see https://developer.atlassian.com/hipchat/guide/hipchat-rest-api
 * @see https://www.hipchat.com/docs/apiv2/method/view_privatechat_history
 */
final class ExportUserHistory extends Command
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var string
     */
    private $dir;

    /**
     * Constructor.
     *
     * @param Client $client
     * @param string $dir
     */
    public function __construct(Client $client, string $dir)
    {
        if (!is_dir($dir)) {
            throw new DomainException("Directory [{$dir}] must be writable");
        }

        $this->client = $client;
        $this->dir = realpath($dir);

        parent::__construct('hipchat:export');
    }

    /**
     * {@inheritdoc}
     */
    public function configure()
    {
        $this
            ->addArgument('token', InputArgument::REQUIRED, 'Auth token.')
            ->addArgument('users', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'IDs or Emails of one or more users.')
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $token = $input->getArgument('token');

        foreach ($input->getArgument('users') as $user) {
            $this->exportHistory($token, $user);
        }
    }

    /**
     *
     * @param string $token
     * @param string $user
     */
    private function exportHistory(string $token, string $user)
    {
        echo "Working on user {$user}...", PHP_EOL;

        $now = date(DATE_ISO8601);
        $limit = 1000;
        $offset = 0;

        try {
            $data = [];
            $page = [];

            do {
                echo "Fetching the {$limit} records from {$offset}...", PHP_EOL;

                $response = $this->client->request(
                    'GET',
                    "v2/user/{$user}/history",
                    [
                        'headers' => [
                            'Authorization' => "Bearer {$token}",
                        ],
                        'query' => [
                            'include_deleted' => 'true',
                            'reverse' => 'true',
                            'max-results' => $limit,
                            'start-index' => $offset,
                            'date' => $now,
                        ],
                    ]
                );

                $result = json_decode((string) $response->getBody(), true);
                
                if (!empty($result['items'])) {
                    $page = $result['items'];
                    $data = array_merge($data, $page);
                    
                    $offset += $limit;
                }
            } while ($page);

            // Keep windows compatibility
            $file = str_replace(':', '-', "{$now}.{$user}.json");

            if (file_put_contents("{$this->dir}/{$file}", json_encode($data, JSON_PRETTY_PRINT)) === false) {
                throw new RuntimeException('Impossible to write the file');
            }
            
            echo "File [{$file}] created", PHP_EOL;
        } catch (ClientException $e) {
            echo $e->getMessage(), PHP_EOL;
        }
    }
}
