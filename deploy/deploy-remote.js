const { Client } = require("ssh2");
const fs = require("fs");
const path = require("path");

const host = process.env.DEPLOY_HOST || "draxter.ru";
const username = process.env.DEPLOY_USER || "root";
const password = process.env.DEPLOY_PASS || "";
const localRoot = path.join(__dirname, "..");

const uploads = [
  {
    local: path.join(localRoot, "local", "modules", "draxter.aichat"),
    remote: null,
  },
  {
    local: path.join(localRoot, "local", "components", "draxter"),
    remote: null,
  },
  {
    local: path.join(localRoot, "local", "ajax", "draxter_aichat.php"),
    remote: null,
  },
];

function exec(conn, cmd) {
  return new Promise((resolve, reject) => {
    conn.exec(cmd, (err, stream) => {
      if (err) return reject(err);
      let out = "";
      let errOut = "";
      stream
        .on("close", (code) => {
          if (code !== 0) {
            reject(new Error(`Command failed (${code}): ${cmd}\n${errOut || out}`));
          } else {
            resolve(out.trim());
          }
        })
        .on("data", (d) => {
          out += d.toString();
        })
        .stderr.on("data", (d) => {
          errOut += d.toString();
        });
    });
  });
}

function sftpMkdir(sftp, dir) {
  return new Promise((resolve, reject) => {
    sftp.mkdir(dir, (err) => {
      if (err && err.code !== 4) return reject(err);
      resolve();
    });
  });
}

async function sftpEnsureDir(sftp, dir) {
  const parts = dir.split("/").filter(Boolean);
  let cur = dir.startsWith("/") ? "" : "";
  for (const part of parts) {
    cur += "/" + part;
    await sftpMkdir(sftp, cur);
  }
}

function sftpUploadFile(sftp, localPath, remotePath) {
  return new Promise((resolve, reject) => {
    sftp.fastPut(localPath, remotePath, (err) => (err ? reject(err) : resolve()));
  });
}

async function uploadRecursive(sftp, localPath, remotePath) {
  const stat = fs.statSync(localPath);
  if (stat.isFile()) {
    await sftpEnsureDir(sftp, path.posix.dirname(remotePath));
    await sftpUploadFile(sftp, localPath, remotePath);
    console.log("  +", remotePath);
    return;
  }
  await sftpEnsureDir(sftp, remotePath);
  for (const name of fs.readdirSync(localPath)) {
    await uploadRecursive(
      sftp,
      path.join(localPath, name),
      remotePath + "/" + name
    );
  }
}

async function detectSiteRoot(conn) {
  const candidates = [
    "/var/www/root/data/www/draxter.ru",
    "/var/www/www-root/data/www/draxter.ru",
    "/var/www/draxter.ru/data/www/draxter.ru",
    "/var/www/draxter/data/www/draxter.ru",
    "/home/root/www/draxter.ru",
    "/var/www/html",
  ];
  for (const c of candidates) {
    try {
      const out = await exec(conn, `[ -d '${c}/bitrix' ] && echo ok || echo no`);
      if (out.includes("ok")) return c;
    } catch (_) {}
  }
  try {
    const out = await exec(
      conn,
      "find /var/www -maxdepth 5 -type d -name bitrix 2>/dev/null | head -1 | sed 's#/bitrix$##'"
    );
    if (out) return out.split("\n")[0].trim();
  } catch (_) {}
  throw new Error("Не найден корень Bitrix на сервере");
}

async function main() {
  if (!password) {
    throw new Error("DEPLOY_PASS is required");
  }
  const conn = new Client();
  await new Promise((resolve, reject) => {
    conn
      .on("ready", resolve)
      .on("error", reject)
      .connect({
        host,
        port: 22,
        username,
        password,
        readyTimeout: 30000,
      });
  });

  console.log("Connected to", host);
  const siteRoot = await detectSiteRoot(conn);
  console.log("Site root:", siteRoot);

  uploads[0].remote = siteRoot + "/local/modules/draxter.aichat";
  uploads[1].remote = siteRoot + "/local/components/draxter";
  uploads[2].remote = siteRoot + "/local/ajax/draxter_aichat.php";

  await exec(
    conn,
    `mkdir -p '${siteRoot}/local/modules' '${siteRoot}/local/components' '${siteRoot}/local/ajax'`
  );

  const sftp = await new Promise((resolve, reject) => {
    conn.sftp((err, s) => (err ? reject(err) : resolve(s)));
  });

  for (const item of uploads) {
    console.log("Uploading", path.basename(item.local), "→", item.remote);
    await uploadRecursive(sftp, item.local, item.remote);
  }

  conn.end();
  console.log("\nDeploy complete.");
  console.log("Health: https://draxter.ru/local/ajax/draxter_aichat.php?action=health");
}

main().catch((e) => {
  console.error(e.message || e);
  process.exit(1);
});
