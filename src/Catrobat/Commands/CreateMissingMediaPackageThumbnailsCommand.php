<?php

namespace App\Catrobat\Commands;

use App\Catrobat\Services\MediaPackageFileRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Symfony Command to create missing media package thumbnails.
 */
class CreateMissingMediaPackageThumbnailsCommand extends Command
{
  /**
   * @var MediaPackageFileRepository
   */
  private $media_package_file_repository;

  /**
   * CreateMissingMediaPackageThumbnailsCommand constructor.
   */
  public function __construct(MediaPackageFileRepository $media_package_file_repository)
  {
    parent::__construct();
    $this->media_package_file_repository = $media_package_file_repository;
  }

  /**
   * Configures the current command.
   */
  protected function configure()
  {
    $this->setName('catrobat:create:media-package-thumbnails')
      ->setDescription('Creates missing thumbnails for images in media package.')
      ->addOption('force')
    ;
  }

  /**
   * Executes the current command.
   *
   * @param OutputInterface $output [optional]
   *
   * @throws \Exception
   *
   * @return int|null null or 0 if everything went fine, or an error code
   */
  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $username = posix_getpwuid(posix_geteuid())['name'];

    if (
      !$input->getOption('force') &&
      !in_array($username, ['www-data', 'apache', 'httpd', '_www', 'nginx'], true)
    ) {
      throw new \Exception('Please run this command as web server user '.'(e.g. sudo -u www-data bin/console ...) or run with --force.');
    }

    $this->media_package_file_repository->createMissingThumbnails();

    return null;
  }
}
