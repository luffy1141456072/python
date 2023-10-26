import socket
import sys
import json
import os
import time
import binascii
i=1
T1 = time.perf_counter()
HOST = ("112.124.52.174", 9035,)
data = b'{"code": 30000,    "data": {"cow_id": "133206","cam_serialnumber":"7F0AA23PAZA4BAA","cam_channel_id":"1"}}'
        # Create a socket (SOCK_STREAM means a TCP socket)
sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
sock.setblocking(True)
sock.connect(HOST)
try:
    # Connect to server and send data
    sock.sendall(data)
finally:
    print("Sent:     {}".format(data))
try:        
    rece = sock.recv(1024)    
except BlockingIOError as e:
    pass    
received=rece  
print(received)
recvdata=json.loads(received)
sock.close()
#time.sleep(10)
while True:
    data = ('{"code": 40000,    "data": {"sessionid": "'+recvdata['body']['sessionid']+'"}}').encode()
    T1 = time.perf_counter()
    i=i+1
    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    sock.setblocking(True)
    sock.connect(HOST)
    # Create a socket (SOCK_STREAM means a TCP socket)
    try:
        # Connect to server and send data
        try:
            sock.sendall(data)
        except BlockingIOError as e:
            conitnue
    finally:
        print("Sent:     {}".format(data))
    received=b''
    try:
        rece = sock.recv(1024)
    except BlockingIOError as e:
        pass
    received=received+rece
    print((str((rece)))[0:-1])
    T2 =time.perf_counter()
    print('程序运行时间:%s毫秒' % ((T2 - T1)*1000))
    time.sleep(1)
    #input()
