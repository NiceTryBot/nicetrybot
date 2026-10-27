<?php

namespace App\Console\Commands;

use App\Actions\Teams\CreateTeam;
use App\Models\Administrator;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('nicetry:install
    {--email= : The first administrator’s email address (asked for when left out)}
    {--password= : Their password, at least 12 characters (asked for when left out; empty makes a random one, shown once)}
    {--name= : Their name (made from the email address when left out)}')]
#[Description('Set up a new installation: create the first administrator, then show the panel’s URL and the DNS records that point at the edge')]
class InstallNiceTryBot extends Command
{
    /**
     * The shortest password the first administrator may choose.
     */
    private const int MIN_PASSWORD_LENGTH = 12;

    /**
     * The length of a password made for them.
     */
    private const int GENERATED_PASSWORD_LENGTH = 24;

    public function handle(CreateTeam $createTeam): int
    {
        $administrator = Administrator::query()->with('user')->oldest('id')->first();

        if ($administrator !== null) {
            $this->info("NiceTryBot is already installed: {$administrator->user->email} is an administrator. Nothing was changed.");
            $this->showPanelAndDns();

            return self::SUCCESS;
        }

        $email = $this->email();

        if ($email === null) {
            return self::FAILURE;
        }

        [$password, $generated] = $this->password();

        if ($password === null) {
            return self::FAILURE;
        }

        $name = trim((string) $this->option('name')) ?: Str::of(Str::before($email, '@'))->replace(['.', '_', '-', '+'], ' ')->squish()->title()->value();

        $user = DB::transaction(function () use ($createTeam, $email, $password, $name): User {
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $createTeam->handle($user, $user->name."'s Team", isPersonal: true);

            Administrator::query()->create(['user_id' => $user->id]);

            return $user;
        });

        $this->info("Created the first administrator: {$user->email}.");

        if ($generated) {
            $this->line("Their password is <options=bold>{$password}</>");
            $this->warn('It’s shown only this once. Keep it somewhere safe, or change it under Settings → Security after you log in.');
        }

        $this->showPanelAndDns();

        return self::SUCCESS;
    }

    /**
     * Get the administrator's email address from --email or a prompt, or null (with the reason shown)
     * when there's no usable one.
     */
    private function email(): ?string
    {
        $email = $this->option('email');

        if ($email === null && $this->input->isInteractive()) {
            $email = text(
                label: 'Email address for the first administrator',
                placeholder: 'you@example.com',
                required: true,
                validate: fn (string $value): ?string => $this->emailError(Str::lower(trim($value))),
            );
        }

        if ($email === null) {
            $this->error('Give the first administrator’s email address with --email.');

            return null;
        }

        $email = Str::lower(trim((string) $email));

        if (($error = $this->emailError($email)) !== null) {
            $this->error($error);

            return null;
        }

        return $email;
    }

    /**
     * Explain what's wrong with an email address for the first administrator, if anything.
     */
    private function emailError(string $email): ?string
    {
        if (Validator::make(['email' => $email], ['email' => ['required', 'string', 'email', 'max:255']])->fails()) {
            return 'That isn’t an email address.';
        }

        if (User::query()->where('email', $email)->exists()) {
            return "{$email} already has an account. Make it an administrator with: php artisan admin:grant {$email}";
        }

        return null;
    }

    /**
     * Get the administrator's password from --password or a prompt, made at random when neither gives
     * one, with whether it was made; null (with the reason shown) for one that's too short.
     *
     * @return array{0: string|null, 1: bool}
     */
    private function password(): array
    {
        $password = $this->option('password');

        if ($password === null && $this->input->isInteractive()) {
            $password = password(
                label: 'Password for the first administrator',
                hint: sprintf('At least %d characters. Leave it empty to have a random one made.', self::MIN_PASSWORD_LENGTH),
                validate: fn (string $value): ?string => $this->passwordError($value),
            );
        }

        if ($password === null || $password === '') {
            return [Str::password(self::GENERATED_PASSWORD_LENGTH, symbols: false), true];
        }

        if (($error = $this->passwordError((string) $password)) !== null) {
            $this->error($error);

            return [null, false];
        }

        return [(string) $password, false];
    }

    /**
     * Explain what's wrong with a password the administrator chose, if anything. Empty is fine: it
     * means one is made for them.
     */
    private function passwordError(string $password): ?string
    {
        return $password !== '' && mb_strlen($password) < self::MIN_PASSWORD_LENGTH
            ? sprintf('Choose a password of at least %d characters, or leave it out to have one made.', self::MIN_PASSWORD_LENGTH)
            : null;
    }

    /**
     * Show where the panel answers and the DNS records that send the panel and protected sites to
     * the edge (config/edge.php).
     */
    private function showPanelAndDns(): void
    {
        $panelHost = config('edge.panel_host');
        $edgeHostname = Domain::edgeHostname();
        $addresses = Domain::edgeAddresses();

        $this->newLine();
        $this->line('The panel: <options=bold>'.config('app.url').'</>');
        $this->newLine();
        $this->line('Point DNS at the edge:');

        $rows = [];

        foreach (array_values(array_unique(array_filter([$panelHost, $edgeHostname]))) as $hostname) {
            foreach ($addresses as $address) {
                $rows[] = ['A', $hostname, $address];
            }
        }

        if ($edgeHostname !== '') {
            $rows[] = ['CNAME', 'each subdomain you protect, like shop.example.com', $edgeHostname];
        }

        foreach ($addresses as $address) {
            $rows[] = ['A', 'each apex domain you protect, like example.com', $address];
        }

        $this->table(['Type', 'Name', 'Value'], $rows);

        if ($addresses === []) {
            $this->warn('NICETRY_EDGE_ADDRESSES is empty, so there are no A records to give. Set it in .env to this server’s public IPv4 address, then restart the app.');
        }

        if ($edgeHostname === '') {
            $this->warn('NICETRY_EDGE_HOSTNAME is empty, so subdomains have no CNAME target. Set it in .env to a hostname that resolves to this server, then restart the app.');
        }
    }
}
