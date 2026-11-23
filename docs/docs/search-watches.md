# Search Watches

> Added in **PriceBuddyPlus**, a fork of PriceBuddy.

A search watch is a saved search on a second-hand marketplace. PriceBuddy checks it on a
schedule and notifies you about **new listings** that match and about **price drops** on
listings it has already seen, through the same notification channels as price alerts
(email, Pushover, Gotify, Apprise, Telegram, Discord, ntfy and the in-app bell).

Supported marketplaces:

| Marketplace | How it is read | Needs |
|---|---|---|
| Kleinanzeigen (kleinanzeigen.de) | The search results page, which is server-rendered | Nothing |
| eBay.de | eBay's official Browse API (eBay blocks scraping aggressively) | Free API keys, see below |

## Creating a watch

1. Run the search on the marketplace website with everything you want: keywords, category,
   location and radius, price range, private sellers only, and so on.
2. Copy the address of the **results page** (not a single listing).
3. In PriceBuddy open **Search watches → New search watch**, give it a name and paste the URL.
   The marketplace is detected from the URL.
   On Kleinanzeigen, picking a category first (e.g. *Haushaltsgeräte*) keeps a generic query
   such as "mobile klimaanlage" from being swamped by unrelated ads; the category is part of
   the URL you copy.
4. Optionally add filters on top of the search:
   - **Min / max price**. Listings without a price ("VB" only) pass unless **Require a price**
     is on.
   - **Max distance**. Kleinanzeigen shows the distance from your search location on each
     listing. Left empty, the radius in the Kleinanzeigen URL (`…r50` = 50 km) is used, because
     Kleinanzeigen pads short result pages with ads hundreds of kilometres away.
   - **Title must contain one of** and **Skip listings mentioning** (title and description,
     case-insensitive), for example `defekt`, `suche`, `ersatzteile`.
   - **Preview matches** runs the search once with the filters in the form, saved or not, and
     shows which listings would match and why the others would not. Nothing is saved and no
     notification is sent.
5. Pick how often to check. The minimum is 10 minutes; 30–60 minutes is plenty for most
   searches.

The **first check saves a baseline**: everything already listed is recorded without a
notification, so you are not flooded with 25 alerts. Use **Check now** on the watch to run it
immediately and see the baseline.

## Listings

The watch page lists every listing seen, with its price, how far it has dropped since it was
first seen, distance and location. Listings new since your last visit are marked. By default
the table shows listings that match your filters and were still in the results at the last
successful check; use the filters to see listings that are gone, that fail your filters
(kept so a later price drop can bring them back into range), or that have no price.

Saving changed filters re-scores the listings already stored straight away, without sending
notifications.

## Dashboard

The dashboard's **Marketplace finds** section shows every watch with its number of matching
listings, how many are new since you last opened it, and whether its last check worked. Below
that are the best deals, listings priced furthest below the median of everything the watch
has matched (once it has at least four priced matches), and the newest matches. Turn the
section off under **Customize** on the dashboard.

**Track price** turns a listing into a normal PriceBuddy product with price history, and turns
on *notify on every change* for it, so you hear when the seller lowers a negotiable price.
PriceBuddyPlus ships store definitions for Kleinanzeigen and eBay DE ad pages, created on
first use (or with `php artisan buddy:create-stores germany`).

## Used offers on a product

Pick tracked products under **Compare with products** on a watch, for example the Pinguino
watch with your DeLonghi Pinguino products. The product page then lists the watch's
matching listings still on the marketplace as **Used offers**, cheapest first, with how far
each is below the product's current new price.

## Being polite to the marketplace

- Watches are checked one at a time with a pause between them (`SEARCH_WATCH_SLEEP_SECONDS`,
  default 5) and at most `SEARCH_WATCH_MAX_PER_RUN` (default 5) per minute.
- Only the first results page is read, newest first.
- When a marketplace answers **403 or 429**, the watch pauses: 30 minutes, doubling on each
  further block up to a day, or longer if the site sends `Retry-After`. The pause and the last
  error are shown on the watch. A normal error (e.g. HTTP 503) does not pause the watch.

Remember that everything on your network shares one public IP. Very short intervals across
many watches can get that IP blocked for your normal browsing as well.

## eBay API keys

1. Sign in at <https://developer.ebay.com/> and create an application keyset (**Production**).
2. Set `EBAY_CLIENT_ID` (App ID) and `EBAY_CLIENT_SECRET` (Cert ID) in the environment.

The free tier allows about 5,000 Browse API calls a day, far more than a handful of watches
need. Supported search URL parameters: keywords (`_nkw`), category (`_sacat`), price range
(`_udlo`, `_udhi`), Buy It Now / auction (`LH_BIN`, `LH_Auction`), condition
(`LH_ItemCondition`) and *items located in Germany* (`LH_PrefLoc=1`).

## Command line

```bash
# Check every watch that is due (this runs every minute from the scheduler)
php artisan buddy:check-search-watches

# Check one watch now, even if it is not due
php artisan buddy:check-search-watches --watch=3
```

## Credits

The listing parsing, watch-and-diff logic and eBay Browse API approach were informed by these
MIT-licensed projects:
[kleinanzeigen-notifier](https://github.com/TheOnlyLoki/kleinanzeigen-notifier),
[ebay-kleinanzeigen-api](https://github.com/DanielWTE/ebay-kleinanzeigen-api),
[deal-finder](https://github.com/hummat/deal-finder),
[kleinanzeigen-alert](https://github.com/DanielStefanK/kleinanzeigen-alert) and
[kleinanzeigen-telegram-bot](https://github.com/Homura3/kleinanzeigen-telegram-bot).
