# Running the ERP on a free Oracle Cloud server

This puts the Coconut Factory ERP on a server on the internet, so it can be opened from any
phone or computer at `https://<the server's IP address>`. It uses Oracle Cloud's **Always Free**
offer. Later, when you buy a domain name, the same server keeps working — see the last step.

> Good for demos and trials. For a customer's real business data, a small paid server or the
> factory's own computer is more dependable: free servers come with no guarantee or support.

## 1. Create the Oracle Cloud account

1. Go to <https://www.oracle.com/cloud/free/> and click **Start for free**.
2. Choose your **home region** carefully — it cannot be changed later. Pick the one nearest
   Sri Lanka: **India West (Mumbai)** or **Singapore**.
3. A credit or debit card is asked for, to check you are a real person. Always Free servers are
   not charged.
4. *Recommended:* once the account works, upgrade it to **Pay As You Go** (Billing → Upgrade).
   You still pay nothing while you stay within the free limits, and Oracle then stops treating a
   quiet server as abandoned and switching it off.

## 2. Create the server

1. In the Oracle console open **☰ → Compute → Instances → Create instance**.
2. **Name:** `coconut-erp`.
3. **Image:** click *Change image* → **Canonical Ubuntu** → **24.04**.
4. **Shape:** click *Change shape* → **Ampere** → **VM.Standard.A1.Flex** with **2 OCPUs** and
   **12 GB** memory (free up to 4 OCPUs and 24 GB in total).
   If it says *out of capacity*, try another *availability domain* or try again later.
5. **Networking:** keep *Create new virtual cloud network* and *Assign a public IPv4 address*.
6. **SSH keys:** choose *Generate a key pair for me* and click **Save private key**. Keep that file
   safe — it is the only way to log in to the server.
7. Click **Create**. When the instance shows **Running**, note its **Public IP address**.

## 3. Open the web ports in Oracle's console

Oracle blocks web traffic until you allow it.

1. On the instance page click the **Subnet** link, then the **Default Security List**.
2. Click **Add Ingress Rules** and add two rules:
   - Source CIDR `0.0.0.0/0`, IP protocol **TCP**, destination port **80**
   - Source CIDR `0.0.0.0/0`, IP protocol **TCP**, destination port **443**
3. Save. (The installer opens the same ports inside the server itself.)

## 4. Connect to the server

On Ubuntu (or in Windows PowerShell), with the key file you saved:

```bash
chmod 600 ~/Downloads/ssh-key-*.key
ssh -i ~/Downloads/ssh-key-*.key ubuntu@<public IP address>
```

Answer `yes` the first time. You are now typing on the cloud server.

## 5. Install the ERP

On the server:

```bash
wget https://raw.githubusercontent.com/chathurangakr/test-erp/main/deploy/coconut-erp.sh
bash coconut-erp.sh
```

On an Oracle server it asks **nothing** — the `ubuntu` user needs no password there. It finds the
server's public address by itself, opens the web ports, and installs everything (10–20 minutes).
At the end it shows:

- **Open:** `https://<public IP address>`
- **Sign in as** and **Password** — write them down.

Open the address in a browser. It warns that the connection is not private (normal until there
is a domain name): choose **Advanced → Continue**. Sign in, then change the administrator's email
and password and turn on two-factor sign-in in **Settings**.

## 6. Updating

Connect as in step 4 and run:

```bash
bash ~/coconut-erp/deploy/coconut-erp.sh
```

It ends with **Updated from … to …** or **Already up to date**.

## 7. Backups — copy them off the server

The server backs up the database every night into `/var/backups/coconut-erp`. If the server is
lost, so are those copies, so fetch them regularly to your own computer:

```bash
ssh -i ~/Downloads/ssh-key-*.key ubuntu@<public IP address> \
    'sudo tar -C /var/backups -czf - coconut-erp' > coconut-erp-backups-$(date +%F).tar.gz
```

Run it on your own computer (not on the server). It saves one file holding all the backups,
named with today's date.

## 8. A name instead of the IP address — and no more browser warning

1. Get a name that points at the server's public IP address:
   - **free:** sign in at <https://www.duckdns.org>, create a name (e.g. `factory-erp`) and set
     its IP to the server's — you get `factory-erp.duckdns.org`;
   - **bought:** in the domain seller's settings add an **A record**, e.g. `erp.yourcompany.lk`,
     pointing to the server's IP address.
2. On the server, put the name in the settings and run the update:

   ```bash
   sed -i 's/^SERVER_NAME=.*/SERVER_NAME=factory-erp.duckdns.org/' ~/coconut-erp/deploy/provision.env
   bash ~/coconut-erp/deploy/coconut-erp.sh
   ```

   It gets a free **Let's Encrypt** certificate for the name (port 80 must be open, step 3) and
   ends with *The certificate is trusted*. Browsers and phones then open
   `https://factory-erp.duckdns.org` with no warning. The certificate renews itself every few
   months. Your data is kept.
3. If it says it **could not get a certificate**, the name does not point at this server yet
   (DuckDNS changes take a minute or two) or port 80 is blocked; fix that and run step 2 again.

## 9. Only administrators add people (recommended on the internet)

On a server anyone on the internet can reach, turn off self sign-up:

```bash
sed -i 's/^SELF_REGISTRATION=.*/SELF_REGISTRATION=no/' ~/coconut-erp/deploy/provision.env
grep -q '^SELF_REGISTRATION=' ~/coconut-erp/deploy/provision.env || echo 'SELF_REGISTRATION=no' >> ~/coconut-erp/deploy/provision.env
bash ~/coconut-erp/deploy/coconut-erp.sh
```

The sign-in page then has no "Sign up" link. Add people on **Administration → Users → Add user**.

## 10. Moving the data from another computer (or restoring a backup)

`deploy/restore.sh` replaces ALL the ERP data on a server with a backup. It backs up the server's
own data first, and puts it back by itself if anything goes wrong. A backup from an older version
is upgraded as it loads; one from a newer version is refused (update the server first).

**On the old computer** (e.g. the factory PC), once everyone has stopped entering work there:

```bash
sudo /usr/local/sbin/coconut-erp-backup
sudo ls -t /var/backups/coconut-erp | head -n 2
```

The two newest files are a pair with the same date and time, e.g.
`coconut_erp-2026-10-17-1900.sql.gz` and `env-2026-10-17-1900`. Copy **both** to a USB drive or
Google Drive (`sudo cp /var/backups/coconut-erp/*2026-10-17-1900* /media/…/` and then
`sudo chown $USER` the copies). The `env-…` file holds the security key that keeps two-factor
sign-in working — keep it as private as a password.

**Into the server:** in Oracle Cloud Shell use **⚙ → Upload** for both files, then send them on:

```bash
scp -i ~/erp_key coconut_erp-2026-10-17-1900.sql.gz env-2026-10-17-1900 ubuntu@<public IP address>:
```

**On the server:**

```bash
bash ~/coconut-erp/deploy/restore.sh ~/coconut_erp-2026-10-17-1900.sql.gz
```

Type `RESTORE` when asked. Afterwards everyone signs in with the accounts and passwords **from
the old computer**; stop using the old computer for entries from then on, or the two drift apart.
