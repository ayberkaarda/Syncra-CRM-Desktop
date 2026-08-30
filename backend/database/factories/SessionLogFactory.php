<?php

namespace Database\Factories;

use App\Models\SessionLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionLog>
 */
class SessionLogFactory extends Factory
{
    protected $model = SessionLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $loggedInAt = fake()->dateTimeBetween('-30 days', 'now');
        $durationSeconds = fake()->numberBetween(60, 28800);
        $loggedOutAt = fake()->boolean(70)
            ? (clone $loggedInAt)->modify("+{$durationSeconds} seconds")
            : null;

        return [
            'user_id' => null,
            'email' => fake()->safeEmail(),
            'event' => fake()->randomElement(['login', 'logout', 'failed_login']),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'device' => fake()->randomElement(['desktop', 'mobile', 'tablet']),
            'browser' => fake()->randomElement(['Chrome', 'Firefox', 'Edge', 'Safari']),
            'platform' => fake()->randomElement(['Windows', 'macOS', 'Linux', 'iOS', 'Android']),
            'session_id' => fake()->uuid(),
            'logged_in_at' => $loggedInAt,
            'logged_out_at' => $loggedOutAt,
            'duration_seconds' => $loggedOutAt ? $durationSeconds : null,
        ];
    }

    /**
     * Indicate that this is an active login event.
     */
    public function login(): static
    {
        return $this->state(fn (array $attributes) => [
            'event' => 'login',
            'logged_out_at' => null,
            'duration_seconds' => null,
        ]);
    }

    /**
     * Indicate that this is a logout event.
     */
    public function logout(): static
    {
        return $this->state(function (array $attributes) {
            $loggedInAt = $attributes['logged_in_at'] ?? fake()->dateTimeBetween('-30 days', 'now');
            $durationSeconds = fake()->numberBetween(60, 28800);

            return [
                'event' => 'logout',
                'logged_in_at' => $loggedInAt,
                'logged_out_at' => (clone $loggedInAt)->modify("+{$durationSeconds} seconds"),
                'duration_seconds' => $durationSeconds,
            ];
        });
    }

    /**
     * Indicate that this is a failed login attempt.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'event' => 'failed_login',
            'user_id' => null,
            'logged_in_at' => null,
            'logged_out_at' => null,
            'duration_seconds' => null,
        ]);
    }
}
