<?php

namespace App\Catrobat\Controller\Web;

use App\Catrobat\Services\CatroNotificationService;
use App\Catrobat\StatusCode;
use App\Entity\FollowNotification;
use App\Entity\ProgramManager;
use App\Entity\User;
use App\Entity\UserManager;
use App\Repository\CatroNotificationRepository;
use App\Repository\UserCommentRepository;
use App\Utils\ImageUtils;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\Types\GuidType;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Exception\MissingResourceException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Encoder\EncoderFactoryInterface;
use Twig\Error\Error;

class ProfileController extends AbstractController
{
  const MIN_PASSWORD_LENGTH = 6;
  const MAX_PASSWORD_LENGTH = 32;

  /**
   * @Route("/user/{id}", name="profile", defaults={"id": 0}, methods={"GET"})
   * @Route("/user/")  // Overwrite for FosUser Profile Route (We don't use it!)
   *
   * @param GuidType $id
   *
   * @throws Error
   *
   * @return RedirectResponse|Response
   */
  public function profileAction(Request $request, ProgramManager $program_manager, UserManager $user_manager, $id = 0)
  {
    /**
     * @var User
     */
    $user = null;
    $my_profile = false;

    $view = 'UserManagement/Profile/profile.html.twig';

    if (0 === $id || ($this->getUser() && $this->getUser()->getId() === $id))
    {
      $my_profile = true;
      $user = $this->getUser();
      $view = 'UserManagement/Profile/myProfile.html.twig';
    }
    else
    {
      $user = $user_manager->find($id);
    }

    if (!$user)
    {
      return $this->redirectToRoute('fos_user_security_login');
    }

    if ($my_profile)
    {
      $program_count = count($program_manager->getUserPrograms($user->getId()));
    }
    else
    {
      $program_count = count($program_manager->getPublicUserPrograms($id));
    }

    $oauth_user = $user->getGplusUid();

    \Locale::setDefault(substr($request->getLocale(), 0, 2));
    try
    {
      $country = Countries::getName(strtoupper($user->getCountry()));
    }
    catch (MissingResourceException $e)
    {
      $country = '';
    }

    $firstMail = $user->getEmail();
    $secondMail = $user->getAdditionalEmail();
    $followerCount = $user->getFollowers()->count();

    return $this->render($view, [
      'profile' => $user,
      'program_count' => $program_count,
      'follower_count' => $followerCount,
      'country' => $country,
      'firstMail' => $firstMail,
      'secondMail' => $secondMail,
      'oauth_user' => $oauth_user,
      'minPassLength' => self::MIN_PASSWORD_LENGTH,
      'maxPassLength' => self::MAX_PASSWORD_LENGTH,
      'username' => $user->getUsername(),
      'myProfile' => $my_profile,
    ]);
  }

  /**
   * @Route("/countrySave", name="country_save", methods={"POST"})
   *
   * @return JsonResponse|RedirectResponse
   */
  public function countrySaveAction(Request $request, UserManager $user_manager)
  {
    /**
     * @var User
     */
    $user = $this->getUser();

    if (!$user)
    {
      return $this->redirectToRoute('fos_user_security_login');
    }

    $country = $request->request->get('country');

    try
    {
      $this->validateCountryCode($country);
    }
    catch (\Exception $e)
    {
      return JsonResponse::create([
        'statusCode' => $e->getMessage(),
      ]);
    }

    $user->setCountry($country);

    $user_manager->updateUser($user);

    return JsonResponse::create([
      'statusCode' => StatusCode::OK,
    ]);
  }

  /**
   * @Route("/passwordSave", name="password_save", methods={"POST"})
   *
   * @return JsonResponse|RedirectResponse
   */
  public function passwordSaveAction(Request $request, UserManager $user_manager, EncoderFactoryInterface $factory)
  {
    /**
     * @var User
     */
    $user = $this->getUser();
    if (!$user)
    {
      return $this->redirectToRoute('fos_user_security_login');
    }

    $old_password = $request->request->get('oldPassword');

    $encoder = $factory->getEncoder($user);

    $bool = $encoder->isPasswordValid($user->getPassword(), $old_password, $user->getSalt());

    if (!$bool)
    {
      return JsonResponse::create([
        'statusCode' => StatusCode::PASSWORD_INVALID,
      ]);
    }

    $newPassword = $request->request->get('newPassword');
    $repeatPassword = $request->request->get('repeatPassword');

    try
    {
      $this->validateUserPassword($newPassword, $repeatPassword);
    }
    catch (\Exception $e)
    {
      return JsonResponse::create([
        'statusCode' => $e->getMessage(),
      ]);
    }

    if ('' !== $newPassword)
    {
      $user->setPlainPassword($newPassword);
    }

    $user_manager->updateUser($user);

    return JsonResponse::create([
      'statusCode' => StatusCode::OK,
      'saved_password' => 'supertoll',
    ]);
  }

  /**
   * @Route("/emailSave", name="email_save", methods={"POST"})
   *
   * @return JsonResponse|RedirectResponse
   */
  public function emailSaveAction(Request $request, UserManager $user_manager)
  {
    /**
     * @var User
     */
    $user = $this->getUser();
    if (!$user)
    {
      return $this->redirectToRoute('fos_user_security_login');
    }

    $firstMail = $request->request->get('firstEmail');
    $secondMail = $request->request->get('secondEmail');

    if ('' === $firstMail && '' === $secondMail)
    {
      return JsonResponse::create(['statusCode' => StatusCode::USER_EMAIL_MISSING]);
    }

    try
    {
      $this->validateEmail($firstMail);
    }
    catch (\Exception $e)
    {
      return JsonResponse::create(['statusCode' => $e->getMessage(), 'email' => 1]);
    }
    try
    {
      $this->validateEmail($secondMail);
    }
    catch (\Exception $e)
    {
      return JsonResponse::create(['statusCode' => $e->getMessage(), 'email' => 2]);
    }

    if ($this->checkEmailExists($firstMail, $user_manager))
    {
      return JsonResponse::create(['statusCode' => StatusCode::USER_EMAIL_ALREADY_EXISTS, 'email' => 1]);
    }
    if ($this->checkEmailExists($secondMail, $user_manager))
    {
      return JsonResponse::create(['statusCode' => StatusCode::USER_EMAIL_ALREADY_EXISTS, 'email' => 2]);
    }

    if ('' !== $firstMail && $firstMail !== $user->getEmail())
    {
      $user->setEmail($firstMail);
    }
    if ('' !== $firstMail && '' !== $secondMail && $secondMail !== $user->getAdditionalEmail())
    {
      $user->setAdditionalEmail($secondMail);
    }
    if ('' !== $firstMail && '' === $secondMail)
    {
      $user->setAdditionalEmail('');
    }
    if ('' === $firstMail && '' === $secondMail && '' !== $user->getAdditionalEmail())
    {
      $user->setEmail($user->getAdditionalEmail());
      $user->setAdditionalEmail('');
    }
    if ('' === $firstMail && '' !== $secondMail)
    {
      $user->setEmail($secondMail);
      $user->setAdditionalEmail('');
    }
    $user_manager->updateUser($user);

    return JsonResponse::create([
      'statusCode' => StatusCode::OK,
    ]);
  }

  /**
   * @Route("/usernameSave", name="username_save", methods={"POST"})
   *
   * @return JsonResponse|RedirectResponse
   */
  public function usernameSaveAction(Request $request, UserManager $user_manager)
  {
    /**
     * @var User
     */
    $user = $this->getUser();
    if (!$user)
    {
      return $this->redirectToRoute('fos_user_security_login');
    }

    $username = $request->request->get('username');

    if ('' === $username)
    {
      return JsonResponse::create(['statusCode' => StatusCode::USERNAME_MISSING]);
    }

    try
    {
      $this->validateUsername($username);
    }
    catch (\Exception $e)
    {
      return JsonResponse::create(['statusCode' => StatusCode::USERNAME_INVALID]);
    }

    if ($this->checkUsernameExists($username, $user_manager))
    {
      return JsonResponse::create(['statusCode' => StatusCode::USERNAME_ALREADY_EXISTS]);
    }

    $user->setUsername($username);
    $user_manager->updateUser($user);

    return JsonResponse::create([
      'statusCode' => StatusCode::OK,
    ]);
  }

  /**
   * @Route("/userUploadAvatar", name="profile_upload_avatar", methods={"POST"})
   *
   * @return JsonResponse|RedirectResponse
   */
  public function uploadAvatarAction(Request $request, UserManager $user_manager)
  {
    /**
     * @var User
     */
    $user = $this->getUser();
    if (!$user)
    {
      return $this->redirectToRoute('fos_user_security_login');
    }

    $image_base64 = $request->request->get('image');

    try
    {
      $image_base64 = ImageUtils::checkAndResizeBase64Image($image_base64);
    }
    catch (\Exception $e)
    {
      return JsonResponse::create(['statusCode' => $e->getMessage()]);
    }

    $user->setAvatar($image_base64);
    $user_manager->updateUser($user);

    return JsonResponse::create([
      'statusCode' => StatusCode::OK,
      'image_base64' => $image_base64,
    ]);
  }

  /**
   * @Route("/deleteAccount", name="profile_delete_account", methods={"POST"})
   *
   * @throws ORMException
   * @throws OptimisticLockException
   *
   * @return JsonResponse|RedirectResponse
   */
  public function deleteAccountAction(CatroNotificationRepository $notification_repository,
                                      UserCommentRepository $comment_repository)
  {
    /**
     * @var User
     * @var EntityManager $em
     */
    $user = $this->getUser();
    if (!$user)
    {
      return $this->redirectToRoute('fos_user_security_login');
    }

    $user_comments = $comment_repository->getCommentsWrittenByUser($user);

    $em = $this->getDoctrine()->getManager();
    $em->remove($user);
    $em->flush();

    return JsonResponse::create([
      'statusCode' => StatusCode::OK,
      'count' => count($user_comments),
    ]);
  }

  /**
   * @Route("/followUser/{id}", name="follow_user", methods={"GET"}, defaults={"id": 0})
   *
   * @param $id
   *
   * @throws ORMException
   * @throws OptimisticLockException
   *
   * @return RedirectResponse
   */
  public function followUser($id, UserManager $user_manager, CatroNotificationService $notification_service)
  {
    /**
     * @var User
     */
    $user = $this->getUser();
    if (!$user)
    {
      return $this->redirectToRoute('fos_user_security_login');
    }

    if (0 === $id || $id === $user->getId())
    {
      return $this->redirectToRoute('profile');
    }

    /**
     * @var User
     */
    $userToFollow = $user_manager->find($id);
    $user->addFollowing($userToFollow);
    $user_manager->updateUser($user);

    $notification = new FollowNotification($userToFollow, $user);
    $notification_service->addNotification($notification);

    return $this->redirectToRoute('profile', ['id' => $id]);
  }

  /**
   * @Route("/unfollowUser/{id}", name="unfollow_user", methods={"GET"}, defaults={"id": 0})
   *
   * @param GuidType $id
   *
   * @return RedirectResponse
   */
  public function unfollowUser($id, UserManager $user_manager)
  {
    $user = $this->getUser();
    if (!$user)
    {
      return $this->redirectToRoute('fos_user_security_login');
    }

    if (0 === $id)
    {
      return $this->redirectToRoute('profile');
    }

    /**
     * @var User
     */
    $userToUnfollow = $user_manager->find($id);
    $user->removeFollowing($userToUnfollow);
    $user_manager->updateUser($user);

    return $this->redirectToRoute('profile', ['id' => $id]);
  }

  /**
   * @Route("/follow/{type}", name="list_follow", methods={"POST"}, defaults={"_format": "json"}, requirements={"type": "follower|follows"})
   *
   * @param $type
   *
   * @return JsonResponse
   */
  public function listFollow(Request $request, $type, UserManager $user_manager)
  {
    $criteria = Criteria::create()
      ->orderBy(['username' => Criteria::ASC])
      ->setFirstResult($request->get('page') * $request->get('pageSize'))
      ->setMaxResults($request->get('pageSize'))
    ;

    /**
     * @var User
     * @var ArrayCollection $followCollection
     * @var User[]          $users
     */
    $user = $user_manager->find($request->get('id'));
    switch ($type)
    {
      case 'follower':
        $followCollection = $user->getFollowers();
        break;
      case 'follows':
        $followCollection = $user->getFollowing();
        break;
    }
    $length = $followCollection->count();
    $followCollection->first();
    $users = $followCollection->matching($criteria)->toArray();

    $data = [];
    foreach ($users as $user)
    {
      array_push($data, [
        'username' => $user->getUsername(),
        'id' => $user->getId(),
        'avatar' => $user->getAvatar(),
      ]);
    }

    return JsonResponse::create(['profiles' => $data, 'maximum' => $length]);
  }

  /**
   * @param      $code
   * @param null $locale
   *
   * @return string
   */
  public function country($code, $locale = null)
  {
    $countries = Countries::getNames($locale ?: $this->localeDetector->getLocale());
    if (array_key_exists($code, $countries))
    {
      return $this->fixCharset($countries[$code]);
    }

    return '';
  }

  //////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
  //// private functions
  //////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

  /**
   * @param $pass1
   * @param $pass2
   *
   * @throws \Exception
   */
  private function validateUserPassword($pass1, $pass2)
  {
    if ($pass1 !== $pass2)
    {
      throw new \Exception(StatusCode::USER_PASSWORD_NOT_EQUAL_PASSWORD2);
    }

    if (0 === strcasecmp($this->getUser()->getUsername(), $pass1))
    {
      throw new \Exception(StatusCode::USER_USERNAME_PASSWORD_EQUAL);
    }

    if ('' !== $pass1 && strlen($pass1) < self::MIN_PASSWORD_LENGTH)
    {
      throw new \Exception(StatusCode::USER_PASSWORD_TOO_SHORT);
    }

    if ('' !== $pass1 && strlen($pass1) > self::MAX_PASSWORD_LENGTH)
    {
      throw new \Exception(StatusCode::USER_PASSWORD_TOO_LONG);
    }
  }

  /**
   * @param $email
   *
   * @throws \Exception
   */
  private function validateEmail($email)
  {
    $name = '[a-zA-Z0-9]((\.|\-|_)?[a-zA-Z0-9])*';
    $domain = '[a-zA-Z]((\.|\-)?[a-zA-Z0-9])*';
    $tld = '[a-zA-Z]{2,8}';
    $regEx = '/^('.$name.')@('.$domain.')\.('.$tld.')$/';

    if (!preg_match($regEx, $email) && !empty($email))
    {
      throw new \Exception(StatusCode::USER_EMAIL_INVALID);
    }
  }

  /**
   * @param $username
   *
   * @throws \Exception
   */
  private function validateUsername($username)
  {
    // also take a look at /config/validator/validation.xml when applying changes!
    if (null === $username || strlen($username) < 3 || strlen($username) > 180)
    {
      throw new \Exception(StatusCode::USERNAME_INVALID);
    }
  }

  /**
   * @param $country
   *
   * @throws \Exception
   */
  private function validateCountryCode($country)
  {
    //todo: check if code is really from the drop-down
    if (!empty($country) && !preg_match('/[a-zA-Z]{2}/', $country))
    {
      throw new \Exception(StatusCode::USER_COUNTRY_INVALID);
    }
  }

  /**
   * @param $email
   *
   * @return bool
   */
  private function checkEmailExists($email, UserManager $user_manager)
  {
    if ('' === $email)
    {
      return false;
    }

    $userWithFirstMail = $user_manager->findOneBy(['email' => $email]);
    $userWithSecondMail = $user_manager->findOneBy(['additional_email' => $email]);

    if (null !== $userWithFirstMail && $userWithFirstMail !== $this->getUser() || null !== $userWithSecondMail && $userWithSecondMail !== $this->getUser())
    {
      return true;
    }

    return false;
  }

  /**
   * @param $username
   *
   * @return bool
   */
  private function checkUsernameExists($username, UserManager $user_manager)
  {
    if ('' === $username)
    {
      return false;
    }

    $user = $user_manager->findOneBy(['username' => $username]);

    if (null !== $user && $user !== $this->getUser())
    {
      return true;
    }

    return false;
  }
}
