<?php
/**
 * ShopInnKart - facts about THIS deployment that no setting can answer.
 *
 * The security screens are mostly about settings: things an operator chose and
 * can change. This file is for the other kind - conditions that are true of a
 * particular copy of the store on a particular server, which nobody chose and
 * which therefore nobody thinks to look at.
 *
 * ---------------------------------------------------------------------------
 * Why "is the seeded password still in use" is a check and not a note in a doc
 * ---------------------------------------------------------------------------
 * The project ships with a demonstration administrator so that a fresh install
 * can be signed into at all, and that account's password is printed in the
 * README - which is in a public repository. The same row is in schema.sql, so
 * it is also in the dump an owner imports onto the live host.
 *
 * Every piece of advice about it already exists: the README says change it,
 * docs/GO-LIVE.md makes it step one, and the installer refuses to reset it.
 * None of that is a check. Advice is read once, by whoever happened to do the
 * deploy, and a store that went live in a hurry has no way of finding out that
 * it is still open - the admin panel looks exactly the same either way.
 *
 * So the app asks itself, out loud, on the Security screen and from the
 * command line: can anybody who has read the repository sign in here?
 *
 * The passwords below are not secrets and writing them here leaks nothing -
 * they are published in the README of a public repository, which is the entire
 * problem. What matters is that the list is checked with password_verify()
 * against the stored hash, so it cannot produce a false alarm: an account is
 * only named if that password really does open it right now.
 */

declare(strict_types=1);

/**
 * The credentials this project ships with, and where each one comes from.
 *
 * Add to this list rather than changing a seed: an old seed that is still in
 * somebody's database is exactly what this is for.
 */
const DEPLOYMENT_SEEDED_PASSWORDS = [
    'Admin@123' => 'the administrator seeded by database/schema.sql',
    'Test@123'  => 'the demonstration customers seeded by database/schema.sql',
];

/**
 * Which accounts can still be opened with a password from the public README.
 *
 * Only active accounts are reported. A disabled account cannot be signed into,
 * so naming it would be noise on a screen whose whole value is that it is
 * usually empty.
 *
 * Every failure is swallowed and reported as "could not tell": this runs on a
 * settings screen and from a cron self-test, and neither may become a 500
 * because a column is missing on an older database.
 *
 * @return array{checked:bool, admins:list<array{id:int,name:string,email:string,why:string}>,
 *               customers:int, error:?string}
 */
function deployment_seeded_logins(): array
{
    $out = ['checked' => false, 'admins' => [], 'customers' => 0, 'error' => null];

    try {
        $admins = Database::fetchAll(
            "SELECT `id`, `name`, `email`, `password` FROM `admins` WHERE `status` = 'active'"
        );

        foreach ($admins as $admin) {
            $hash = (string) ($admin['password'] ?? '');
            if ($hash === '') {
                continue;
            }
            foreach (DEPLOYMENT_SEEDED_PASSWORDS as $password => $why) {
                if (password_verify($password, $hash)) {
                    $out['admins'][] = [
                        'id'    => (int) $admin['id'],
                        'name'  => (string) $admin['name'],
                        'email' => (string) $admin['email'],
                        'why'   => $why,
                    ];
                    break;
                }
            }
        }

        // Customers are counted rather than listed. One demonstration shopper
        // left behind is untidy; it is not somebody else's admin panel, and a
        // list of customer emails does not belong on a settings screen.
        foreach (Database::fetchAll(
            "SELECT `password` FROM `users` WHERE `status` = 'active' AND `password` <> ''"
        ) as $user) {
            foreach (DEPLOYMENT_SEEDED_PASSWORDS as $password => $why) {
                if (password_verify($password, (string) $user['password'])) {
                    $out['customers']++;
                    break;
                }
            }
        }

        $out['checked'] = true;
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
    }

    return $out;
}

/**
 * One sentence for a screen or a self-test, or null when there is nothing to say.
 *
 * Deliberately blunt about consequence rather than about policy. "Weak
 * password" invites a shrug; "anybody who reads the repository can sign in"
 * does not.
 */
function deployment_seeded_logins_message(?array $result = null): ?string
{
    $result = $result ?? deployment_seeded_logins();

    if ($result['error'] !== null) {
        return 'Could not check whether the seeded passwords are still in use.';
    }

    $admins    = count($result['admins']);
    $customers = (int) $result['customers'];

    if ($admins === 0 && $customers === 0) {
        return null;
    }

    if ($admins > 0) {
        $names = implode(', ', array_map(
            static fn (array $a): string => $a['email'],
            array_slice($result['admins'], 0, 3)
        ));
        if ($admins > 3) {
            $names .= ' and ' . ($admins - 3) . ' more';
        }

        return $admins === 1
            ? 'The administrator ' . $names . ' still signs in with the password printed in the '
                . 'project README, which is a public repository. Anyone who reads it can open this '
                . 'panel. Change it now - hiding the login address does not help, because they have '
                . 'the key rather than needing to find the door.'
            : $admins . ' administrators (' . $names . ') still sign in with passwords printed in the '
                . 'project README, which is a public repository. Anyone who reads it can open this '
                . 'panel. Change them now - hiding the login address does not help, because they have '
                . 'the key rather than needing to find the door.';
    }

    return $customers === 1
        ? 'One demonstration customer account still uses the seeded password. Remove it before the '
            . 'store takes real orders.'
        : $customers . ' demonstration customer accounts still use the seeded password. Remove them '
            . 'before the store takes real orders.';
}
