<?php

namespace App\Console\Commands;

use App\Actions\Platform\OnboardBusiness as OnboardBusinessAction;
use App\Enums\DeviceType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Taking on a customer, from the terminal.
 *
 * The platform panel will do this through the same action, so this command is
 * not a stopgap that gets thrown away — it stays useful for setting a shop up
 * over the phone, and for rehearsing the whole thing before a visit.
 */
class OnboardBusiness extends Command
{
    protected $signature = 'scanzo:onboard
        {--business= : The shop name}
        {--owner= : The owner\'s name}
        {--email= : The owner\'s email, used to sign in}
        {--password= : Leave out to be asked}
        {--branch=الفرع الرئيسي : First branch name}
        {--branch-code=MAIN : First branch code, appears nowhere the customer sees}
        {--device=موبايل البائع : First till name}
        {--device-code=A1 : First till code, the invoice number prefix}
        {--desktop : The first till is a computer, not a phone}
        {--trial-days=14 : How long the trial runs}';

    protected $description = 'Set up a new subscriber: business, subscription, branch, owner and first till';

    public function handle(OnboardBusinessAction $onboard): int
    {
        $business = $this->option('business') ?: $this->ask('اسم النشاط التجاري');
        $owner = $this->option('owner') ?: $this->ask('اسم المالك');
        $email = $this->option('email') ?: $this->ask('إيميل المالك');
        $password = $this->option('password') ?: $this->secret('الباسورد') ?: 'password';

        $validator = Validator::make(
            ['business' => $business, 'owner' => $owner, 'email' => $email, 'password' => $password],
            [
                'business' => ['required', 'string', 'max:255'],
                'owner' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $result = $onboard->handle(
            businessName: $business,
            ownerName: $owner,
            ownerEmail: $email,
            ownerPassword: $password,
            branchName: $this->option('branch'),
            branchCode: $this->option('branch-code'),
            trialDays: (int) $this->option('trial-days'),
            deviceName: $this->option('device'),
            deviceCode: $this->option('device-code'),
            deviceType: $this->option('desktop') ? DeviceType::Desktop : DeviceType::Mobile,
        );

        $this->newLine();
        $this->components->info('تم إنشاء '.$result['business']->name);

        $this->table(['', ''], [
            ['Business', $result['business']->name.'  ('.$result['business']->slug.')'],
            ['Trial ends', $result['subscription']->ends_at->toDateString()],
            ['Branch', $result['branch']->name.'  ['.$result['branch']->code.']'],
            ['Owner', $result['owner']->email],
            ['Till', $result['device']->name.'  ['.$result['device']->code.'] → invoices '.$result['device']->code.'-000001'],
        ]);

        $this->newLine();
        $this->line('  Next, with the owner signed in:');
        $this->line('    1. add the rest of the branches, the sellers and their tills');
        $this->line('    2. load the catalogue, then print the labels');
        $this->line('    3. on the seller\'s phone: open the site and add it to the home screen');
        $this->newLine();

        return self::SUCCESS;
    }
}
