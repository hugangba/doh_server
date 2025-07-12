import mysql.connector
import dns.resolver
import dns.message
import dns.rdatatype
import time
import logging
import random

# 配置项
UPSTREAM_DNS_SERVERS = ['8.8.8.8', '1.1.1.1']  # 上游 DNS 服务器
TTL_THRESHOLD = 3600  # 1 小时（秒）
DB_CONFIG = {
    'host': 'localhost',
    'port': 3306,
    'user': 'your_username',
    'password': 'your_password',
    'database': 'db_username',
    'charset': 'utf8'
}
TABLE_IPV4 = 'dns_records_ipv4'
TABLE_IPV6 = 'dns_records_ipv6'

def query_upstream_dns(domain, qtype):
    """查询上游 DNS 服务器，返回 IPv4 或 IPv6 地址"""
    resolver = dns.resolver.Resolver()
    resolver.timeout = 5
    resolver.lifetime = 5

    # 随机打乱上游 DNS 服务器顺序
    servers = UPSTREAM_DNS_SERVERS.copy()
    random.shuffle(servers)

    for server in servers:
        resolver.nameservers = [server]
        try:
            if qtype == dns.rdatatype.A:
                answers = resolver.resolve(domain, qtype)
                for rdata in answers:
                    return str(rdata)  # 返回第一个 IPv4 地址
            elif qtype == dns.rdatatype.AAAA:
                answers = resolver.resolve(domain, qtype)
                for rdata in answers:
                    return str(rdata)  # 返回第一个 IPv6 地址
        except Exception as e:
            logging.error(f"DNS query failed for {domain} (type {qtype}) on {server}: {str(e)}")
            continue
    return None

def update_expired_records():
    """遍历数据库，更新过期记录的 IP 地址和时间戳"""
    try:
        # 连接数据库
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
    except mysql.connector.Error as e:
        logging.error(f"Database connection failed: {str(e)}")
        return

    current_time = int(time.time())

    try:
        # 更新 IPv4 记录
        cursor.execute(f"""
            SELECT domain, ipv4, timestamp
            FROM {TABLE_IPV4}
            WHERE timestamp IS NULL OR timestamp < %s
        """, (current_time - TTL_THRESHOLD,))
        ipv4_records = cursor.fetchall()

        for record in ipv4_records:
            domain = record['domain']
            new_ip = query_upstream_dns(domain, dns.rdatatype.A)
            if new_ip:
                try:
                    cursor.execute(f"""
                        UPDATE {TABLE_IPV4}
                        SET ipv4 = %s, timestamp = %s
                        WHERE domain = %s
                    """, (new_ip, current_time, domain))
                    conn.commit()
                except mysql.connector.Error as e:
                    logging.error(f"Failed to update IPv4 for {domain}: {str(e)}")

        # 更新 IPv6 记录
        cursor.execute(f"""
            SELECT domain, ipv6, timestamp
            FROM {TABLE_IPV6}
            WHERE timestamp IS NULL OR timestamp < %s
        """, (current_time - TTL_THRESHOLD,))
        ipv6_records = cursor.fetchall()

        for record in ipv6_records:
            domain = record['domain']
            new_ip = query_upstream_dns(domain, dns.rdatatype.AAAA)
            if new_ip:
                try:
                    cursor.execute(f"""
                        UPDATE {TABLE_IPV6}
                        SET ipv6 = %s, timestamp = %s
                        WHERE domain = %s
                    """, (new_ip, current_time, domain))
                    conn.commit()
                except mysql.connector.Error as e:
                    logging.error(f"Failed to update IPv6 for {domain}: {str(e)}")

    except mysql.connector.Error as e:
        logging.error(f"Database query failed: {str(e)}")
    finally:
        cursor.close()
        conn.close()

if __name__ == '__main__':
    update_expired_records()
