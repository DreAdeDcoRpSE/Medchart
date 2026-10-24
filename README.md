# MedChart

**A simple, web-based medical record manager for keeping track of medications, doctors, and allergies, with easy-to-print information for medical appointments.**

MedChart is a self-hosted PHP/MySQL application designed to keep important medical information organized in one place. Instead of trying to remember medication names, doses, allergies, and which doctor handles what, you can maintain a list and print it or save it as a PDF when you need it.

It works in a web browser on a desktop, phone, or tablet. You can host it on a compatible web server or run it locally using a PHP environment such as WAMP.

> **Project status:** Web version available. A standalone desktop version is something I would like to build in the future; it is not included in this release.

## Why I made MedChart

This script was designed and built out of a need.

My mother was diagnosed with cancer many years ago. Over the years, with all the treatments and doctor visits, it became a mess trying to keep track of her medications, doctors, allergies, and all the other information that almost every doctor asks for when you go in for an appointment. They want to make sure everything in their charts is up to date, and understandably so.

For a while, my mother kept handwritten notes, but that eventually became too much to keep up with. My sister then made a simple chart in Excel, which helped, but my mother wasn't comfortable making changes to it herself. So I took it upon myself to write something that would be easier for her to use.

The idea was pretty simple: keep everything in one place, make it easy to update, and have a button to print the information whenever she had an appointment. If needed, we could also create a PDF to send to a doctor's office.

Every doctor and nurse we've shown it to has loved the idea and appreciated having an organized, up-to-date list handed to them. It makes their job a little easier, and it saves us from having to write the same information out over and over again.

I've had a working version of MedChart running for my mother for about two years. Now that I've had more time, I decided to finish it up and make the web version available for others who might find it useful.

The web version can be accessed from a PC, phone, or tablet, provided you can reach the server it's installed on. If it's hosted online, you'll need an internet connection. If it's installed locally, you can access it through your local setup.

I'm also looking into making a standalone desktop version that won't require setting up a web server. The encrypted Import/Export feature was built with that idea in mind, so information can be transferred between compatible MedChart installations without having to enter everything again.

## Screenshots

These are placeholder paths. Put your images in a `screenshots/` folder in the GitHub repository, then rename the files or update the paths below to match your actual filenames.

### Dashboard

The main page for viewing and managing your medical information.

<a href="https://github.com/user-attachments/assets/44b9fdc9-6dea-4594-a28a-c5192fd29b23">
  <img
    src="https://github.com/user-attachments/assets/44b9fdc9-6dea-4594-a28a-c5192fd29b23"
    alt="MedChart Dashboard"
    width="500"
  >
</a>

### Medications in action

Activate or deactivate medications and rearrange their order using drag-and-drop.

<a href="https://github.com/user-attachments/assets/b733d4da-b5c7-4a3d-9560-2af39c80f8f5">
  <img
    src="https://github.com/user-attachments/assets/b733d4da-b5c7-4a3d-9560-2af39c80f8f5"
    alt="MedChart medication management demonstration"
    width="500"
  >
</a>

### Mobile view

The responsive layout makes it possible to use MedChart on smaller screens.

<a href="https://github.com/user-attachments/assets/3e700fb9-2263-4df2-b3d9-7cf6bbbd3a69">
  <img
    src="https://github.com/user-attachments/assets/3e700fb9-2263-4df2-b3d9-7cf6bbbd3a69"
    alt="MedChart Mobile view"
    height="500"
  >
</a>

### Encrypted Import and Export

Move your medical information between compatible MedChart installations using password-protected transfer files.

<a href="https://github.com/user-attachments/assets/51a79ebe-60d8-4b8b-8978-36e048cd51d4">
  <img
    src="https://github.com/user-attachments/assets/51a79ebe-60d8-4b8b-8978-36e048cd51d4"
    alt="MedChart Import and Export page"
    height="500"
  >
</a>

### Admin Control Panel

Manage user accounts and application administration from the ACP.

<a href="https://github.com/user-attachments/assets/ac9eb053-58ab-4edc-ad41-7c9bee43cb15">
  <img
    src="https://github.com/user-attachments/assets/ac9eb053-58ab-4edc-ad41-7c9bee43cb15"
    alt="MedChart Admin Control Panel"
    height="500"
  >
</a>

### Printer-friendly page

A clean, printable summary you can take to an appointment or save as a PDF.

<a href="https://github.com/user-attachments/assets/f7a8b7a6-42c3-4f4a-889b-b225dccb0150">
  <img
    src="https://github.com/user-attachments/assets/f7a8b7a6-42c3-4f4a-889b-b225dccb0150"
    alt="MedChart printer-friendly medical summary"
    height="500"
  >
</a>

## Features

### Medical information

- **Medications:** Keep track of medication names, doses, and frequency. Mark medications active or inactive and arrange them in the order you prefer.
- **Allergies:** Maintain a list of allergies that can be included in your medical summary.
- **Medical team:** Keep a list of doctors and other healthcare providers, including their roles and phone numbers.
- **Patient information:** Store basic patient details and notes in one place.
- **Printer-friendly summary:** Print an organized list to bring to appointments.
- **PDF output:** Generate a PDF copy when you need a digital document.

### Accounts and administration

- Individual user accounts, with each account's medical records kept separate.
- Administrator controls for managing accounts and approvals.
- Password reset and account security features.
- Security event logging.
- Responsive interface with light, dark, and automatic theme options.

### Encrypted Import and Export

MedChart can export your medical information to a password-protected `.medchart` file and import it into another compatible installation.

- **Export:** Create an encrypted transfer file using a password or passphrase you choose.
- **Preview:** Review what an import contains before applying it.
- **Merge:** Add imported information while avoiding duplicate entries where supported.
- **Replace:** Replace the applicable existing medical information with the imported data.
- **Portable data:** The transfer file is encrypted independently of the web server's installation-specific encryption key.

**Important:** Keep your transfer password safe. If you forget it, MedChart cannot recover the contents of that encrypted transfer file. Treat exported files as sensitive, even though they are encrypted.

## Requirements

For the public web installer, use a server that meets the requirements displayed by the installer, including:

- PHP **8.1 or newer** (recommended for public installations).
- MySQL or MariaDB, with an empty database available for a fresh installation.
- Required PHP extensions, including MySQL database support, OpenSSL, and JSON.
- PHP sessions and secure random-byte generation.
- Permission for the installer to create the application's protected configuration files.
- A web server such as Apache or another appropriately configured PHP-capable server.

> **PHP 7.4 note:** An existing MedChart installation has been used with PHP 7.4 after a compatibility adjustment, but the public installer targets PHP 8.1+. PHP 7.4 is end-of-life and is not recommended for a new deployment, particularly when storing medical information.

## Installing MedChart

These instructions are for a **new installation**. If you're updating an existing MedChart installation, do not run the fresh installer or import a fresh-install SQL schema over your existing database.

1. Download the MedChart release and extract it.
2. Upload the application files to a folder on your web server, or place them in your local PHP web directory.
3. Create a **new, empty** MySQL/MariaDB database and a database user with the necessary permissions.
4. Open the MedChart URL in your browser. If the application isn't configured yet and the installer is present, it should redirect you to `/install/` automatically.
5. Review the system requirements screen and resolve any failed checks.
6. Enter your database connection details and create the initial administrator account.
7. Complete installation, then sign in with the administrator account you created.
8. Follow the installer's cleanup guidance. Remove the `install/` directory once installation is complete and you've confirmed everything works.

**Example (local WAMP installation):** If you extract MedChart to `C:\wamp64\www\medchart`, open `http://localhost/medchart/` in your browser. Your WAMP installation path may be different.

### Important configuration files

A configured installation uses files in the `secret/` directory, including:

| File | Purpose |
| --- | --- |
| `secret/database.php` | Database connection settings |
| `secret/secret.php` | Installation-specific encryption key |
| `secret/installed.lock` | Marks the installation as completed |
| `secret/mail.php` | Optional email configuration, when used |

**Do not publish these files with real credentials or keys. Do not replace an existing installation's encryption key.** Losing or changing that key can make existing encrypted records unreadable.

## Using MedChart

After signing in, enter your patient information, medications, allergies, and medical team details. You can update the lists whenever something changes, then use the printer-friendly page or PDF feature when you need a current copy for an appointment.

If you're moving between installations, use **Import / Export**. Always check the preview and choose carefully between **Merge** and **Replace**. Back up your current information before performing a Replace operation.

## Security and privacy

MedChart handles sensitive information, so the security of the server it's running on matters just as much as the application itself.

- Use **HTTPS** when accessing MedChart over the internet.
- Keep PHP, your database server, and your web server updated.
- Use strong, unique account passwords and transfer passphrases.
- Restrict access to configuration files and protect the `secret/` directory.
- Make regular backups of your database **and** your installation-specific encryption key, and store them securely.
- Do not upload real patient information in screenshots, bug reports, or public GitHub issues.
- Test restores and imports using non-sensitive data when possible.

MedChart is a personal record-keeping tool. **It is not an electronic health record system for clinical use, a medical device, or a substitute for medical advice.** Always verify medication and allergy information with your healthcare professionals.

## Email notifications

MedChart supports the server's PHP mail functionality and optional SMTP configuration. If you want email features such as password reset messages, configure email for your hosting environment and send a test message before relying on it. See the included email setup documentation, if present in your release.

## Future plans

The web version is the first completed version of MedChart. A future goal is a standalone desktop application that can run without setting up PHP, MySQL, or a web server. The encrypted `.medchart` transfer format is intended to help move records between compatible versions.

This is a plan, not a promise of a release date.


## ❤️ Support MedChart

MedChart is a free project that I develop and maintain in my spare time.

If you find MedChart useful and would like to support its continued development, you can make a donation through PayPal. Donations are completely optional, but they're always appreciated and help keep the project going.

[![Donate with PayPal](https://img.shields.io/badge/Donate-PayPal-0070BA?style=for-the-badge&logo=paypal&logoColor=white)](https://www.paypal.com/donate/?hosted_button_id=PTAZ2DD7CTDVG)

**Thank you for your support!**


## License

MedChart is intended to be released under the **GNU General Public License v3.0 (GPL-3.0)**. See the repository's `LICENSE` file for the full terms.

Third-party components, including Dompdf, retain their own copyright notices and licenses. Their license files should remain included with the distribution.

## Feedback and issues

If you find a bug or have an idea for improving MedChart, feel free to open an issue on GitHub. Please include the MedChart version, PHP version, what you were doing, and any relevant error message—but **never include personal medical records, passwords, database credentials, or encryption keys**.

---

*MedChart started as a way to make one person's medical appointments a little easier. Hopefully it can do the same for others.*
