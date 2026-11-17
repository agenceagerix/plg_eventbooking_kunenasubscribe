# Events Booking - Kunena Subscribe

Joomla plugin (group `eventbooking`) that subscribes [Events Booking](https://joomdonation.com/joomla-extensions/events-booking-joomla-events-registration.html)
registrants to the [Kunena](https://www.kunena.org) forum categories chosen on each event,
so they receive the forum's e-mail notifications without having to subscribe themselves.

Typical use: one private forum category per workshop or session, where only the people
registered for that session can read, post, and get notified.

## What it does

- Adds a **Kunena** tab to the Events Booking event form: pick one or more Kunena categories.
- When a registration is confirmed (payment success, or free registration confirmed),
  the registrant is subscribed to those categories.
- When a registration is cancelled, the registrant is unsubscribed, unless another active
  registration of the same user uses the same category.
- Optional checkbox on the event form: subscribe the registrants **already confirmed**
  for this event (useful when the forum is added after registrations opened).
- Creates the user's Kunena profile row if missing. Kunena only notifies subscribers that
  have one, and it is normally created on the first forum visit: without it, a registrant
  who never opened the forum would receive nothing.

## What it does NOT do

**Access control.** This plugin only manages subscriptions. Kunena re-checks access before
every notification, so a subscribed user who has no access receives nothing. Give access
with a Joomla user group per event, for instance with the core
**Events Booking - Joomla Groups** plugin, and set the Kunena category access type to
that group.

## Requirements

- Joomla 5.x, PHP 8.1+ (Kunena 7.1 itself requires PHP 8.3)
- Events Booking 5.x
- Kunena 6.x or 7.x

## Installation

1. Download the zip from the [releases](../../releases) and install it with
   *System > Install > Extensions*.
2. Enable **Events Booking - Kunena Subscribe** in *System > Plugins*.
3. Open an event, tab **Kunena**, select the categories, save.

### Plugin options

| Option | Default | Meaning |
|---|---|---|
| Subscribe offline payment registrants | No | Yes: subscribe as soon as an offline-payment registration is stored. No: when it is approved. |
| Unsubscribe when cancelled | Yes | Unsubscribe on cancellation, keeping categories still used by another active registration. |
| Show on frontend | No | Show the tab on the frontend event submission form. |

## Notes

- Subscriptions are stored in `#__kunena_user_categories` (`subscribed = 1`), the table
  Kunena itself uses. Read-tracking columns are never modified.
- Kunena's own setting *Category subscriptions* must not be *disabled*.

## Support

Provided **as is**, without warranty or support commitment. Issues and pull requests are
welcome but may not be answered.

## License

GNU General Public License version 2 or later, see [LICENSE.txt](LICENSE.txt).

© 2026 Agence AGERIX
