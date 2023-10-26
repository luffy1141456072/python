# encoding:utf-8
#name:socket.py
'''
网络配置及线程管理模块
'''
import threading
import os
import man_config as conf
import socket
import socketserver 
import json
import imginfo
import time
import sessioninfo
from track import track_class
import cv2
from meliae import scanner
import random
import paramiko
import dbop
import imgget
import trackBasedOnImgFirst
import trackBasedOnImgSecond
import camimgget
import cameralocate
import camurlget
from track import TrackerGet
import sessioninfo as se
import sys
import re
import gc
import cProfile
from memory_profiler import profile
import traceback
import camsnapurlget
from track.detection.yolo import YOLO
import logging

BUF_SIZE=conf.getConfig("network","BUF_SIZE");
os.system('svn --username daoerji  --password 3jktt46gr up /home/')
#TODO:加锁,try expect

class myHandler(socketserver.BaseRequestHandler):
    def __init__(self,request, client_address, server,dict):
        self.dict = dict
        if not (client_address[0]=='100.1.13.0' or client_address[0]=='100.1.13.128'):         
            super().__init__(request, client_address, server)
    
   # @profile(precision=4,stream=open('/home/memory_profiler.log','w+'))
    def handle(self):
        timenow=time.time()
        destryTime=int(conf.getConfig("info","destryTime"))
        address,pid = self.client_address
        BUF_SIZE = conf.getConfig("network","BUF_SIZE");
        try:
            self.data = self.request.recv(999999)
        except ConnectionResetError:
            return
        #print('%s connected!'%address)
        #print(self.data)
        try:
            recvdata=json.loads(self.data)
        except:
            self.request.sendall('{"msg": "json syntax error","code": 9999}\r\n'.encode('utf-8'))
            #print("json syntax error code 9999")
            return
        else:               
               if recvdata["code"]==30000:#session id get
                   serverLoad=int(conf.getConfig("camera","serverLoad"));
                   sessionID=hash(str(time.time()))
                   latestdata=0
                   timenow=time.time()
                   """
                   tr:
                       for key in self.dict:
                           if key == 'tracker':
                               continue
                           if key == 'detecter':
                               continue
                           if key == 'db':
                               continue
                           latestdata=latestdata+1
                   except:
                       self.request.sendall('{"msg": "server is full, please wait and retry","code": 30004}'.encode('utf-8'))
                       print('{msg": "server is full, please wait and retry","code": 30004}')
                       self.request.close()
                       return"""
                   if len(self.dict) > serverLoad+3 :
                       self.request.sendall('{"msg": "server is full, please wait and retry","code": 30004}'.encode('utf-8'))
                       print('{msg": "server is full, please wait and retry","code": 30004}')
                       self.request.close()
                   else:
                       t1=time.perf_counter()
                       print('receive=',self.data.decode('utf-8'))
                       print('sessionID='+str(sessionID))
                       self.dict[sessionID]=se.sessionInfo(sessionID)
                       self.dict[sessionID].putRunningFlag()
                       self.dict[sessionID].getLocked()
                       self.dict[sessionID].putDB(dbop.dbop())
                       self.dict[sessionID].putCamLocateObj(cameralocate.cameraLocate(recvdata["data"]["cow_id"],db=self.dict[sessionID].getDB()))
                       camobj=self.dict[sessionID].getCamLocateObj()
                       self.cam=camobj.cameraLocate()
                       if self.cam==1:
                           self.request.sendall('{"msg": "no camera in ranch","code": 30001}'.encode('utf-8'))
                           print('{"msg": "no camera in ranch","code": 30001}'+str(sessionID))
                           self.request.close()
                           return 
                       elif self.cam==2:
                           self.request.sendall('{"msg": "cannot find camera for the cow","code": 30002}'.encode('utf-8'))
                           print('{"msg": "cannot find camera for the cow","code": 30002} sessionid='+str(sessionID))
                           self.request.close()
                           return 
                       elif self.cam==0:
                           self.request.sendall('{"msg": "cannot find camera for the cow","code": 30002}'.encode('utf-8'))
                           print('{"msg": "cannot find camera for the cow","code": 30002} sessionid='+str(sessionID))
                           self.request.close()
                           return 
                       self.dict[sessionID].putCamInfo(self.cam)
                       try:
                           print(self.cam[3])
                           print(self.cam[21])
                           url=camurlget.camUrlGet(self.cam[3],self.cam[21],db=self.dict[sessionID].getDB())
                       except:
                           self.request.sendall(('{"msg": "failed", "code": 30004,"body": ""}').encode())
                           self.request.close()
                           traceback.print_exc()
                           return
                       if url[0]==1:
                           self.request.sendall(('{"msg": "failed", "code": 30004,"body": "'+str(url[1])+'"}').encode())
                           self.request.close()
                           print('{"msg": "failed", "code": 30004,"body": ""}')
                       else:
                           self.dict[sessionID].putCamURL(url[0],url[1],url[2])
                           self.request.sendall(('{"code": 0, "msg": "success", "body": {"sessionid": "'+str(sessionID)+'","url":"'+url[0]+'","urls":"'+url[1]+'","cover":"'+url[2]+'"}}').encode())
                           self.request.close()
                       try:
                           resu=trackBasedOnImgFirst.trackBasedOnImgFirst(recvdata=recvdata,
                                   dictionary=self.dict,
                                   request=self.request,
                                   cam_serialnumber=self.cam[3],
                                   cam_channel_id=self.cam[21],
                                   sessionID=sessionID,
                                   db=self.dict[sessionID].getDB())
                           if resu == 1:
                               self.dict[sessionID].setNotFindFlag()
                               print('{"msg": "there is no cow in image","code": 30003}')
                               print('{"msg": "there is no cow in image","code": 30003}'.encode('utf-8'))
                           try:
                               if resu[0] == 2:
                                   print(('{"msg": "failed", "code": 30004,"body": "'+str(resu[1])+'"}').encode())
                           except:
                               pass
                           self.dict[sessionID].putStopingFlag()
                           self.dict[sessionID].releaseLock()
                       except Exception as e:
                               traceback.print_exc()
                        #TODO:现在为cpu模式，gpu模式需处理track/detector/yolo.py ,修改all.conf 中线程数量
               elif recvdata["code"]==20000:#next image
                   data=camsnapurlget.camsnapurlget(recvdata["data"]['cam_serialnumber'],recvdata["data"]['cam_channel_id'],db=dict['db'])
                   if(data==1):
                       self.request.sendall(('{"code": 20001,"msg": "request failed, please retry","body": {}}').encode('utf-8'))
                   else:
                       self.request.sendall(('{"code": 0, "msg": "success", "body": {"capture":"'+str(data)+'"}}').encode())
               elif recvdata["code"]==10000:#live url get by cameraid
                   try:
                       url=camurlget.camUrlGet(recvdata['data']['cam_serialnumber'],recvdata['data']['cam_channel_id'],db=self.dict['db'])
                   except:                     
                       self.request.sendall(('{"code": 10001, "msg": "failed,camera error", "body": {}}').encode())
                       return
                       
                   #if url[0]==1:
                    #   self.request.sendall(('{"msg": "failed", "code": 50001,"body": '+url[1]+'}').encode())
                   #else:
                   print('{"code": 0, "msg": "success", "body": {"url":"'+url[0]+'","urls":"'+url[1]+'","cover":"'+url[2]+'"}}')
                   self.request.sendall(('{"code": 0, "msg": "success", "body": {"url":"'+url[0]+'","urls":"'+url[1]+'","cover":"'+url[2]+'"}}').encode())
               elif recvdata["code"]==50000:
                   timenow=time.time()
                   print("clear memory")
                   for key in self.dict:
                       if key == 'tracker':
                           continue
                       if key == 'detecter':
                           continue
                       if key == 'db':
                           continue
                       #print(sys.getrefcount(self.dict[key]))
                       print((timenow-self.dict[key].getLastAccessTime()))
                       if (timenow-self.dict[key].getLastAccessTime())>destryTime:
                           del self.dict[key]
               elif recvdata["code"]==40000:
                   t1=time.perf_counter()
                   sessionid = int(recvdata["data"]["sessionid"])
                   timenow=time.time()
                   try:
                       tmp=self.dict[sessionid].getLastAccessTime()
                   except:
                       self.request.sendall(('{"code": 40003,"msg": "failed,sessionid error, please request sessionid again","body": {}').encode('utf-8'))
                       print('{"code": 40003,"msg": "failed, sessionid error, please request sessionid again","body": {}')
                       return
                   result=self.dict[sessionid].getLastBbox()
                   self.dict[sessionid].getLastAccessTime()
                   if not result[1]==0:
                       camurl=self.dict[sessionid].getCamURL()
                       self.request.sendall(('{"code": 0,"msg": "success","body": {"img_width":"'+str(result[2][1])+'","img_height":"'+str(result[2][0])+'","cow_ox":"'+str(result[0][0])+'","cow_oy":"'+str(result[0][1])+'","cow_width":"'+str(result[0][2])+'","cow_height":"'+str(result[0][3])+'","url":"'+camurl[0]+'","urls":"'+camurl[1]+'","cover":"'+camurl[2]+'"}}\r\n').encode('utf-8'))
                   else:
                       self.request.sendall(('{"code": 40004,"msg": "failed, cannot find cow or device error","body": {}').encode('utf-8'))
                   if self.dict[sessionid].checkLock():
                       return
                   self.dict[sessionid].getLocked()
                   accessLimitTime = int(conf.getConfig("camera","accessLimitTime"))
                   print(str(not self.dict[sessionid].getRunflag()))
                   if((timenow-self.dict[sessionid].getLastAccessTime() < accessLimitTime) or (self.dict[sessionid].getLastBbox() == [0, 0, 0]) or not self.dict[sessionid].getRunflag()):
                       self.dict[sessionid].putRunningFlag()
                       [caminfo,offsetStat]=self.dict[sessionid].getCamInfo(self.dict[sessionid].getCamLocateObj(), self.dict[sessionid].getDB())
                       if caminfo == 1:
                           return                       
                       t2=time.perf_counter()
                       #print('half time'+str((t2-t1)*1000))
                       #offsetStat=0
                       try:
                           result=trackBasedOnImgSecond.trackBasedOnImgSecond(
                                   dictionary=self.dict,
                                   cam=caminfo,
                                   sessionID=sessionid,
                                   db=self.dict[sessionid].getDB(),
                                   offsetStat=offsetStat)
                           if result == 1:
                               #self.dict[sessionid].getLogging().info(str(sessionid)+'{"code": 40001,"msg": "failed, server busy, please wait and retry","body": {}')
                               print(('{"code": 40001,"msg": "failed, server busy, please wait and retry","body": {}').encode('utf-8'))
                           elif result == 0:
                               #self.dict[sessionid].getLogging().info(str(sessionid)+'{"code": 40002,"msg": "failed, cannot find cow in camera, please wait and retry","body": {}')
                               print(('{"code": 40002,"msg": "failed, cannot find cow in camera, please wait and retry","body": {}}').encode('utf-8'))
                           elif result[0] == 2:
                               print(('{"code": 40005,"msg": "failed, camera '+str(result[1])+' error, please check camsera","body": {}').encode('utf-8'))
                          # elif result[1]=='tracking':
                               #self.dict[sessionid].getLogging().info('success')
                           #    self.request.sendall(('{"code": 0,"msg": "success","body": {"img_width":"'+str(result[3])+'","img_height":"'+str(result[2])+'","cow_ox":"'+str(result[0][0])+'","cow_oy":"'+str(result[0][1])+'","cow_width":"'+str(result[0][3])+'","cow_height":"'+str(result[0][2])+'"}}\r\n').encode('utf-8'))
                            #   print('postprocess')
                           elif result[0] == 3:
                               print(('{"code": 40005,"msg": "failed, camera error, please check camsera","body": {"data":"'+json.loads(result[1].decode('gbk'))['errMsg']+'"}').encode('utf-8'))
                       except:
                           traceback.print_exc()
                   sys.stdout.flush()
                   t2=time.perf_counter()
                   self.dict[sessionid].putStopingFlag()
                   print("-----------------------------------------")
                   print('calculate time '+str((t2-t1)*1000))
                   print("----------------------------------------------")
                   self.dict[sessionid].releaseLock()
               elif recvdata["code"]==60000:
                   try:
                       sessionid = int(recvdata["data"]["sessionid"])
                       self.dict[sessionid].getLastAccessTime()
                   except Exception as e:
                       self.request.sendall(('{"code": 0,"msg": "success","body": {"status":"0","expired_time":""}}\r\n').encode('utf-8'))
                       return
                   destryTime = int(conf.getConfig("info","destryTime"))
                   self.request.sendall(('{\
                                       "code": 0,\
                                       "msg": "success",\
                                       "body": {\
                                       "status":"'+str(1 if time.time() < (self.dict[sessionid].getLastAccessTime()+destryTime) else 0)+'",\
                                       "expired_time":"'+time.strftime("%Y-%m-%d %H:%M:%S",time.localtime(self.dict[sessionid].getLastAccessTime()+destryTime+28800))+'"\
                                       }}\r\n').encode('utf-8'))

               elif recvdata["code"]==80000:
                   os._exit()
                   #scanner.dump_all_objects('/home/dump.txt')
               else:
                    self.request.sendall('{"msg": "json code error","code": 9998}\r\n'.encode('utf-8'))
                    #print("json code error code 9998")   
                    
    @classmethod
    def Creator(cls, *args, **kwargs):
        def _HandlerCreator(request, client_address, server):
            cls(request, client_address, server, *args, **kwargs)
        return _HandlerCreator

if __name__ == "__main__":
    HOST = conf.getConfig("network","listenHost");
    PORT = conf.getConfig("network","listenPort");
    ADDR = (HOST,int(PORT))
    name = 'handle'
    dict={}
    #dict['tracker']=TrackerGet.TrackerGet()
    dict['detecter']=YOLO()
    dict['db']=dbop.dbop()
    server = socketserver.ThreadingTCPServer((HOST,int(PORT)),myHandler.Creator(dict))
    print('listening')
    server.serve_forever()  
    print(server)
