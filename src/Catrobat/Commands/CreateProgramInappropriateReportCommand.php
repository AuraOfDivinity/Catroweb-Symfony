<?php

namespace App\Catrobat\Commands;

use App\Catrobat\Commands\Helpers\RemixManipulationProgramManager;
use App\Catrobat\Commands\Helpers\ResetController;
use App\Entity\Program;
use App\Entity\User;
use App\Entity\UserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CreateProgramInappropriateReportCommand extends Command
{
  /**
   * @var UserManager
   */
  private $user_manager;

  /**
   * @var RemixManipulationProgramManager
   */
  private $remix_manipulation_program_manager;

  /**
   * @var ResetController
   */
  private $reset_controller;

  /**
   * CreateProgramInappropriateReportCommand constructor.
   */
  public function __construct(UserManager $user_manager,
                              RemixManipulationProgramManager $program_manager,
                              ResetController $reset_controller)
  {
    parent::__construct();
    $this->user_manager = $user_manager;
    $this->remix_manipulation_program_manager = $program_manager;
    $this->reset_controller = $reset_controller;
  }

  protected function configure()
  {
    $this->setName('catrobat:report')
      ->setDescription('Report a project')
      ->addArgument('user', InputArgument::REQUIRED, 'User who reports on program')
      ->addArgument('program_name', InputArgument::REQUIRED, 'Name of program  which gets reported')
      ->addArgument('note', InputArgument::REQUIRED, 'Report message')
    ;
  }

  /**
   * @throws \Exception
   *
   * @return int|void|null
   */
  protected function execute(InputInterface $input, OutputInterface $output)
  {
    /**
     * @var User
     * @var Program $program
     */
    $username = $input->getArgument('user');
    $program_name = $input->getArgument('program_name');
    $note = $input->getArgument('note');

    $user = $this->user_manager->findUserByUsername($username);
    $program = $this->remix_manipulation_program_manager->findOneByName($program_name);

    if (null == $user || null == $program || null == $this->reset_controller)
    {
      return;
    }

    if ($program->getUser() === $user)
    {
      return;
    }

    try
    {
      $this->reset_controller->reportProgram($program, $user, $note);
    }
    catch (\Exception $e)
    {
      return;
    }
    $output->writeln('Reporting '.$program->getName());
  }
}
